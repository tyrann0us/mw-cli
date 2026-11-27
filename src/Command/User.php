<?php

declare(strict_types=1);

namespace MwCli\Command;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Session\SessionManager;
use MediaWiki\Session\SessionManagerInterface;
use MediaWiki\User\User as WikiUser;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Lists, shows, creates, changes and deletes user accounts, and manages their groups.
 */
class User extends Command
{
    public const NAMES = [
        'addGroup' => 'add-group',
        'removeGroup' => 'remove-group',
        'assignGroups' => 'set-group',
        'listRights' => 'list-rights',
    ];

    private const FIELDS = ['id', 'name', 'email', 'registration', 'groups', 'edits', 'realname'];

    /**
     * Lists user accounts, sorted by name.
     *
     * ## OPTIONS
     *
     * [--group=<group>]
     * : Only members of this group, e.g. sysop.
     *
     * [--prefix=<prefix>]
     * : Only names that start with this.
     *
     * [--limit=<n>]
     * : List at most this many users.
     *
     * [--format=<format>]
     * : table, json, csv, yaml, ids or count.
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show: id, name, email, registration, groups, edits,
     * realname.
     * Default: id, name, email, registration, groups.
     *
     * [--field=<field>]
     * : Prints only the value of this field.
     *
     * ## EXAMPLES
     *
     *     mw user list --group=sysop
     *     mw user list --prefix=Bot --field=name
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function list(array $args, array $assocArgs): void
    {
        $db = $this->services()->getConnectionProvider()->getReplicaDatabase();
        $query = self::userQuery($db)->orderBy('user_name');
        if (isset($assocArgs['group'])) {
            [$group] = self::checkGroups(
                [(string) $assocArgs['group']],
                $this->services()->getUserGroupManager()->listAllGroups()
            );
            $query->join('user_groups', null, 'ug_user = user_id')
                ->where(['ug_group' => $group])
                ->andWhere(self::unexpired($db));
        }
        if (isset($assocArgs['prefix'])) {
            $prefix = strtr((string) $assocArgs['prefix'], '_', ' ');
            $prefix = $this->services()->getContentLanguage()->ucfirst($prefix);
            $query->andWhere(
                $db->expr('user_name', IExpression::LIKE, new LikeValue($prefix, $db->anyString()))
            );
        }
        $this->applyLimit($query, $assocArgs);
        $rows = [];
        foreach ($query->fetchResultSet() as $row) {
            /** @var stdClass $row */
            $rows[(int) $row->user_id] = $row;
        }
        // The groups take a query of their own, so only when they are shown.
        // ids and count print no groups unless --fields or --field asks for them.
        $default = in_array($assocArgs['format'] ?? '', ['ids', 'count'], true) ? '' : 'groups';
        $shown = isset($assocArgs['field']) ? [$assocArgs['field']]
            : array_map('trim', explode(',', (string) ($assocArgs['fields'] ?? $default)));
        $needsGroups = in_array('groups', $shown, true);
        $groups = $needsGroups ? $this->groupsOf(array_keys($rows)) : [];
        $items = [];
        foreach ($rows as $id => $row) {
            $items[] = self::item($row, $groups[$id] ?? []);
        }
        Formatter::items($items, $assocArgs, ['id', 'name', 'email', 'registration', 'groups']);
    }

    /**
     * Shows one user account.
     *
     * ## OPTIONS
     *
     * <user>
     * : User name, or ID if no user has that name.
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show: id, name, email, registration, groups, edits,
     * realname.
     *
     * [--field=<field>]
     * : Prints only the value of this field.
     *
     * ## EXAMPLES
     *
     *     mw user get Admin
     *     mw user get 1 --field=groups
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function get(array $args, array $assocArgs): void
    {
        $user = $this->existing($args[0]);
        // From the row: User::getEditCount() would write a missing count to the database. From
        // the primary, so a lagging replica can't report a just-created user as missing.
        $db = $this->services()->getConnectionProvider()->getPrimaryDatabase();
        $row = self::userQuery($db)->where(['user_id' => $user->getId()])->fetchRow();
        $row instanceof stdClass || Runner::error("User '{$user->getName()}' doesn't exist.");
        $groups = $this->services()->getUserGroupManager()->getUserGroups($user);
        Formatter::item(self::item($row, $groups), $assocArgs);
    }

    /**
     * Creates a user account.
     *
     * Without --password, the account has no password: the user can set one through
     * "Forgot your password?" if an email address is given, or an admin sets it later.
     *
     * ## OPTIONS
     *
     * <name>
     * : User name.
     *
     * [<email>]
     * : Email address.
     *
     * [--password]
     * : Read the password from stdin, so it doesn't show up in the shell history.
     *
     * [--porcelain]
     * : Print only the user ID, for scripts.
     *
     * ## EXAMPLES
     *
     *     mw user create Alice alice@example.org
     *     printf '%s' "$PASSWORD" | mw user create Bob --password
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function create(array $args, array $assocArgs): void
    {
        $user = $this->services()->getUserFactory()->newFromName($args[0], 'creatable')
            ?? Runner::error("Invalid user name '$args[0]'.");
        if ($user->isRegistered()) {
            Runner::error("User '{$user->getName()}' already exists.");
        }
        $email = $args[1] ?? '';
        if ($email !== '' && !Sanitizer::validateEmail($email)) {
            Runner::error("Invalid email address '$email'.");
        }
        $password = empty($assocArgs['password']) ? null : $this->password($user);

        $performer = $this->performer();
        // Through AuthManager, like createAndPromote.php, so the configured providers can act. It
        // also counts the new user in the site statistics. Its own log entry would name the new
        // user as the creator, so the entry is written below instead.
        $status = $this->services()->getAuthManager()->autoCreateUser(
            $user,
            AuthManager::AUTOCREATE_SOURCE_MAINT,
            false,
            false
        );
        // Rare (e.g. a provider refuses the name), so not tested, and kept to one line.
        // isGood(), not isOK(): an account that exists after all comes back OK with a warning.
        $status->isGood() || Runner::error($this->statusText($status));
        if ($password !== null) {
            $this->changePassword($user, $password);
        }
        if ($email !== '') {
            $user->setEmail($email);
            $user->saveSettings();
        }
        // As Special:CreateAccount logs an account made by someone else.
        $log = self::logEntry('newusers', 'create2');
        $log->setPerformer($performer);
        $log->setTarget($user->getUserPage());
        $log->setParameters(['4::userid' => $user->getId()]);
        $log->publish($log->insert());

        if (!empty($assocArgs['porcelain'])) {
            Runner::line((string) $user->getId());
            return;
        }
        Runner::success("Created user '{$user->getName()}' (ID {$user->getId()}).");
    }

    private function changePassword(WikiUser $user, string $password): void
    {
        $changed = $user->changeAuthenticationData([
            'username' => $user->getName(),
            'password' => $password,
            'retype' => $password,
        ]);
        // MediaWiki only applies the change when the status is good, not just OK.
        $changed->isGood()
            || Runner::error('Setting the password failed: ' . $this->statusText($changed));
    }

    /**
     * MediaWiki's session manager: a service since 1.45, before that a singleton.
     */
    private function sessionManager(): SessionManagerInterface
    {
        $legacy = version_compare(MW_VERSION, '1.45', '<');
        $services = $this->services();
        // One line, so it is covered whichever MediaWiki version runs the tests.
        $manager = $legacy ? SessionManager::singleton() : $services->getService('SessionManager');
        /** @var SessionManagerInterface $manager */
        return $manager;
    }

    /**
     * The password from stdin, checked against the password policy.
     */
    private function password(WikiUser $user): string
    {
        if (stream_isatty(STDIN)) {
            Runner::error('--password reads the password from stdin. Pipe it in.');
        }
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
        if ($password === '') {
            Runner::error('Invalid password: empty.');
        }
        $valid = $user->checkPasswordValidity($password);
        if (!$valid->isGood()) {
            Runner::error('Invalid password: ' . $this->statusText($valid));
        }
        return $password;
    }

    /**
     * Changes a user's email address, real name or password.
     *
     * ## OPTIONS
     *
     * <user>
     * : User name, or ID if no user has that name.
     *
     * [--email=<email>]
     * : New email address. An empty value removes it.
     *
     * [--real-name=<name>]
     * : New real name. An empty value removes it.
     *
     * [--password]
     * : Read a new password from stdin, so it doesn't show up in the shell history.
     *
     * ## EXAMPLES
     *
     *     mw user update Alice --email=alice@example.org --real-name="Alice Example"
     *     printf '%s' "$PASSWORD" | mw user update Bob --password
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function update(array $args, array $assocArgs): void
    {
        $user = $this->existing($args[0]);
        $changes = isset($assocArgs['email']) || isset($assocArgs['real-name'])
            || !empty($assocArgs['password']);
        if (!$changes) {
            Runner::error('Pass --email, --real-name or --password.');
        }
        $email = isset($assocArgs['email']) ? (string) $assocArgs['email'] : null;
        if ($email !== null && $email !== '' && !Sanitizer::validateEmail($email)) {
            Runner::error("Invalid email address '$email'.");
        }
        if (!empty($assocArgs['password']) && $user->isSystemUser()) {
            Runner::error("User '{$user->getName()}' is a system user and can't have a password.");
        }
        $password = empty($assocArgs['password']) ? null : $this->password($user);

        if ($password !== null) {
            $this->changePassword($user, $password);
            // Like a password reset: sessions and "remember me" logins with the old one end.
            $this->sessionManager()->invalidateSessionsForUser($user);
        }
        if ($email !== null) {
            $user->setEmail($email);
        }
        if (isset($assocArgs['real-name'])) {
            $user->setRealName((string) $assocArgs['real-name']);
        }
        if ($email !== null || isset($assocArgs['real-name'])) {
            $user->saveSettings();
        }
        Runner::success("Updated user '{$user->getName()}'.");
    }

    /**
     * Deletes user accounts that were never used.
     *
     * MediaWiki can't delete an account with edits, uploads or log entries (other than account
     * creations): block it instead.
     * This removes an unused account the way maintenance/removeUnusedAccounts.php does.
     *
     * ## OPTIONS
     *
     * <user>...
     * : User names, or IDs if no user has that name.
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * ## EXAMPLES
     *
     *     mw user delete Spambot123 --yes
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function delete(array $args, array $assocArgs): void
    {
        $db = $this->services()->getConnectionProvider()->getPrimaryDatabase();
        $users = [];
        $actors = [];
        foreach ($args as $arg) {
            $user = $this->existing($arg);
            $users[$user->getId()] = $user;
            $actors[$user->getId()] = $this->deletableActor($db, $user);
        }
        $names = self::quoted(array_map(static fn (WikiUser $user): string => $user->getName(), $users));
        $question = count($users) === 1 ? "Delete user $names?" : "Delete users $names?";
        Runner::confirm($question, $assocArgs);

        $watchlist = $this->services()->getWatchedItemStore();
        $blocks = $this->services()->getDatabaseBlockStore();
        foreach ($users as $id => $user) {
            // Only expired blocks are left: an active one stopped the deletion above.
            $blocks->deleteBlocksMatchingConds(['bt_user' => $id]);
            $watchlist->clearUserWatchedItems($user) || $watchlist->clearUserWatchedItemsUsingJobQueue($user);
            $this->services()->getTalkPageNotificationManager()->removeUserHasNewMessages($user);
            $this->services()->getBotPasswordStore()->removeUserPasswords($user->getName());
            self::deleteAccount($db, $id, $user->getName(), $actors[$id]);
            $user->clearSharedCache('refresh');
            Runner::success("Deleted user '{$user->getName()}'.");
        }
        DeferredUpdates::addUpdate(SiteStatsUpdate::factory(['users' => -count($users)]));
    }

    /**
     * Lists the rights a user has through their groups.
     *
     * Rights come from $wgGroupPermissions, so they change with the user's groups.
     *
     * ## OPTIONS
     *
     * <user>
     * : User name, or ID if no user has that name.
     *
     * [--limit=<n>]
     * : List at most this many rights.
     *
     * [--format=<format>]
     * : table, json, csv, yaml, ids or count.
     *
     * ## EXAMPLES
     *
     *     mw user list-rights Alice
     *     mw user list-rights Alice --format=json
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function listRights(array $args, array $assocArgs): void
    {
        $user = $this->existing($args[0]);
        $rights = $this->services()->getPermissionManager()->getUserPermissions($user);
        sort($rights);
        // A user has a few dozen rights at most, so they are cut here, not in a query.
        $rights = array_slice($rights, 0, $this->limit($assocArgs));
        Formatter::items(
            array_map(static fn (string $right): array => ['right' => $right], $rights),
            $assocArgs,
            ['right']
        );
    }

    /**
     * Adds a user to groups.
     *
     * The change is logged in the user rights log, by the user from --user.
     *
     * ## OPTIONS
     *
     * <user>
     * : User name, or ID if no user has that name.
     *
     * <group>...
     * : Groups, e.g. sysop or bureaucrat.
     *
     * [--reason=<reason>]
     * : Reason for the user rights log.
     *
     * ## EXAMPLES
     *
     *     mw user add-group Alice sysop
     *     mw user add-group Bot bot --reason="Approved bot"
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function addGroup(array $args, array $assocArgs): void
    {
        [$user, $before, $groups] = $this->groupArgs($args, true);
        foreach (array_intersect($groups, $before) as $group) {
            Runner::warning("User '{$user->getName()}' is already in group '$group'.");
        }
        $this->saveGroups($user, $before, array_diff($groups, $before), [], $assocArgs);
    }

    /**
     * Removes a user from groups.
     *
     * The change is logged in the user rights log, by the user from --user.
     *
     * ## OPTIONS
     *
     * <user>
     * : User name, or ID if no user has that name.
     *
     * <group>...
     * : Groups, e.g. sysop or bureaucrat.
     *
     * [--reason=<reason>]
     * : Reason for the user rights log.
     *
     * ## EXAMPLES
     *
     *     mw user remove-group Alice sysop
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function removeGroup(array $args, array $assocArgs): void
    {
        [$user, $before, $groups] = $this->groupArgs($args, false);
        foreach (array_diff($groups, $before) as $group) {
            Runner::warning("User '{$user->getName()}' isn't in group '$group'.");
        }
        $this->saveGroups($user, $before, [], array_intersect($groups, $before), $assocArgs);
    }

    /**
     * Sets a user's groups: adds the given ones and removes all others.
     *
     * The change is logged in the user rights log, by the user from --user.
     *
     * ## OPTIONS
     *
     * <user>
     * : User name, or ID if no user has that name.
     *
     * [<group>...]
     * : Groups the user should be in. None removes the user from all groups.
     *
     * [--reason=<reason>]
     * : Reason for the user rights log.
     *
     * ## EXAMPLES
     *
     *     mw user set-group Alice sysop bureaucrat
     *     mw user set-group Alice
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function assignGroups(array $args, array $assocArgs): void
    {
        [$user, $before, $groups] = $this->groupArgs($args, false);
        $add = array_diff($groups, $before);
        $remove = array_diff($before, $groups);
        if (!$add && !$remove) {
            Runner::warning("User '{$user->getName()}' already has exactly these groups.");
        }
        $this->saveGroups($user, $before, $add, $remove, $assocArgs, true);
    }

    /**
     * The user, their current groups and the checked group arguments.
     *
     * @param string[] $args The user, then the groups.
     * @return array{0: WikiUser, 1: string[], 2: string[]}
     */
    private function groupArgs(array $args, bool $configuredOnly): array
    {
        $user = $this->existing(array_shift($args) ?? '');
        $manager = $this->services()->getUserGroupManager();
        // From the primary, like the writes, so a lagging replica can't misreport them.
        $before = $manager->getUserGroups($user, IDBAccessObject::READ_LATEST);
        // A group removed from the config can still be removed from a user who has it.
        $known = $configuredOnly ? $manager->listAllGroups() : [...$manager->listAllGroups(), ...$before];
        return [$user, $before, self::checkGroups(array_values(array_unique($args)), $known)];
    }

    /**
     * Adds and removes groups, logs it in the user rights log and reports what changed.
     *
     * @param string[] $before
     * @param string[] $add
     * @param string[] $remove
     * @param array<string,string|bool> $assocArgs
     */
    private function saveGroups(
        WikiUser $user,
        array $before,
        array $add,
        array $remove,
        array $assocArgs,
        bool $set = false
    ): void {

        if (!$add && !$remove) {
            return;
        }
        // Before the first write: an invalid --user fails here, not after the groups changed.
        $performer = $this->performer();
        $manager = $this->services()->getUserGroupManager();
        // A hook can refuse a change, so only what changed is logged and reported.
        $added = array_filter($add, static fn (string $group): bool
            => $manager->addUserToGroup($user, $group));
        $removed = array_filter($remove, static fn (string $group): bool
            => $manager->removeUserFromGroup($user, $group));
        $name = $user->getName();
        foreach ([...array_diff($add, $added), ...array_diff($remove, $removed)] as $group) {
            Runner::warning("A hook stopped the change of group '$group' for user '$name'.");
        }
        // A hook refusing every change is rare and not tested, so kept to one line.
        ($added || $removed) || Runner::error("No group of user '$name' was changed.");

        $after = $manager->getUserGroups($user, IDBAccessObject::READ_LATEST);
        $log = self::logEntry('rights', 'rights');
        $log->setPerformer($performer);
        $log->setTarget($user->getUserPage());
        $log->setComment((string) ($assocArgs['reason'] ?? ''));
        $log->setParameters(['4::oldgroups' => $before, '5::newgroups' => $after]);
        $log->publish($log->insert());

        $list = static fn (array $groups): string => (count($groups) === 1 ? 'group ' : 'groups ')
            . self::quoted($groups);
        if (!$set && !$removed) {
            Runner::success("Added user '$name' to " . $list($added) . '.');
            return;
        }
        if (!$set) {
            Runner::success("Removed user '$name' from " . $list($removed) . '.');
            return;
        }
        sort($after);
        Runner::success("Set the groups of user '$name' to "
            . ($after ? self::quoted($after) : 'none') . '.');
    }

    /**
     * 'a', 'b', 'c'
     *
     * @param string[] $items
     */
    private static function quoted(array $items): string
    {
        return "'" . implode("', '", $items) . "'";
    }

    /**
     * The actor ID of a user that can be deleted (null if it never acted), or fails.
     */
    private function deletableActor(IDatabase $db, WikiUser $user): ?int
    {
        $name = $user->getName();
        if ($user->isSystemUser()) {
            Runner::error("User '$name' is a system user and can't be deleted.");
        }
        $actor = $db->newSelectQueryBuilder()->select('actor_id')->from('actor')
            ->where(['actor_user' => $user->getId()])->caller(__METHOD__)->fetchField();
        $actor = $actor === false ? null : (int) $actor;
        if (self::used($db, $user->getId(), $actor)) {
            Runner::error("User '$name' has edits, uploads or log entries, "
                . "so it can't be deleted. Block it instead.");
        }
        // Deleting it would leave the block without its user. Expired blocks don't count.
        if ($this->services()->getBlockManager()->getBlock($user, null, false) !== null) {
            Runner::error("User '$name' is blocked, so it can't be deleted.");
        }
        return $actor;
    }

    /**
     * Whether the user has stashed uploads, or its actor has edits, uploads or log entries
     * other than account creations.
     */
    private static function used(IDatabase $db, int $id, ?int $actor): bool
    {
        // The upload stash refers to the user, not the actor.
        $stashed = $db->newSelectQueryBuilder()->select('1')->from('uploadstash')
            ->where(['us_user' => $id])->limit(1)->caller(__METHOD__)->fetchField();
        if ($stashed !== false || $actor === null) {
            return $stashed !== false;
        }
        $checks = [
            'revision' => 'rev',
            'archive' => 'ar',
            'image' => 'img',
            'oldimage' => 'oi',
            'filearchive' => 'fa',
        ];
        // MediaWiki 1.44+ has the new file schema, which can hold file revisions.
        $checks += version_compare(MW_VERSION, '1.44', '>=') ? ['filerevision' => 'fr'] : [];
        foreach ($checks as $table => $prefix) {
            $found = $db->newSelectQueryBuilder()->select('1')->from($table)
                ->where(["{$prefix}_actor" => $actor])->limit(1)->caller(__METHOD__)->fetchField();
            if ($found !== false) {
                return true;
            }
        }
        return $db->newSelectQueryBuilder()->select('1')->from('logging')
            ->where(['log_actor' => $actor, $db->expr('log_type', '!=', 'newusers')])
            ->limit(1)->caller(__METHOD__)->fetchField() !== false;
    }

    /**
     * Removes an unused account, like maintenance/removeUnusedAccounts.php.
     */
    private static function deleteAccount(IDatabase $db, int $id, string $name, ?int $actor): void
    {
        $rows = [
            'user' => 'user_id',
            'user_groups' => 'ug_user',
            'user_former_groups' => 'ufg_user',
            'user_properties' => 'up_user',
        ];
        foreach ($rows as $table => $field) {
            $db->newDeleteQueryBuilder()->deleteFrom($table)->where([$field => $id])
                ->caller(__METHOD__)->execute();
        }
        // The account's creation entries, whoever created it. Accounts it created keep theirs.
        $logIds = $db->newSelectQueryBuilder()->select('log_id')->from('logging')
            ->where([
                'log_type' => 'newusers',
                'log_namespace' => NS_USER,
                'log_title' => strtr($name, ' ', '_'),
            ])
            ->caller(__METHOD__)->fetchFieldValues();
        if ($logIds) {
            $logRows = [
                'logging' => 'log_id',
                'log_search' => 'ls_log_id',
                'recentchanges' => 'rc_logid',
            ];
            foreach ($logRows as $table => $field) {
                $db->newDeleteQueryBuilder()->deleteFrom($table)->where([$field => $logIds])
                    ->caller(__METHOD__)->execute();
            }
        }
        if ($actor === null) {
            return;
        }
        $refs = ['logging' => 'log_actor', 'block' => 'bl_by_actor'];
        $referenced = false;
        foreach ($refs as $table => $field) {
            $referenced = $referenced || $db->newSelectQueryBuilder()->select('1')->from($table)
                ->where([$field => $actor])->limit(1)->caller(__METHOD__)->fetchField() !== false;
        }
        // A referenced actor stays without its user, like removeUnusedAccounts.php does.
        $referenced
            ? $db->newUpdateQueryBuilder()->update('actor')->set(['actor_user' => null])
                ->where(['actor_id' => $actor])->caller(__METHOD__)->execute()
            : $db->newDeleteQueryBuilder()->deleteFrom('actor')->where(['actor_id' => $actor])
                ->caller(__METHOD__)->execute();
    }

    /**
     * A registered user by name or ID, or fails.
     */
    private function existing(string $name): WikiUser
    {
        return $this->registeredUser($name) ?? Runner::error("User '$name' doesn't exist.");
    }

    /**
     * The groups, if all are known, or fails naming the unknown ones.
     *
     * @param string[] $groups
     * @param string[] $known
     * @return string[]
     */
    private static function checkGroups(array $groups, array $known): array
    {
        $unknown = array_diff($groups, $known);
        if ($unknown) {
            $known = array_unique($known);
            sort($known);
            $what = count($unknown) === 1 ? 'group' : 'groups';
            Runner::error("Unknown $what " . self::quoted($unknown) . '. Use one of: '
                . implode(', ', $known) . '.');
        }
        return $groups;
    }

    private static function userQuery(IReadableDatabase $db): SelectQueryBuilder
    {
        return $db->newSelectQueryBuilder()
            ->select([
                'user_id',
                'user_name',
                'user_registration',
                'user_editcount',
                'user_email',
                'user_real_name',
            ])
            ->from('user')
            ->caller(__METHOD__);
    }

    /**
     * A condition for memberships that haven't expired.
     */
    private static function unexpired(IReadableDatabase $db): string
    {
        // A string: PHPStan can't read the operator types that Expression::or() declares.
        return 'ug_expiry IS NULL OR ug_expiry >= ' . $db->addQuotes($db->timestamp());
    }

    /**
     * The current groups of these users, in one query.
     *
     * @param int[] $ids
     * @return array<int,string[]>
     */
    private function groupsOf(array $ids): array
    {
        $db = $this->services()->getConnectionProvider()->getReplicaDatabase();
        $groups = [];
        // Batches keep the IN list within database limits on big wikis.
        foreach (array_chunk($ids, 1000) as $batch) {
            $result = $db->newSelectQueryBuilder()
                ->select(['ug_user', 'ug_group'])
                ->from('user_groups')
                ->where(['ug_user' => $batch])
                ->andWhere(self::unexpired($db))
                ->orderBy(['ug_user', 'ug_group'])
                ->caller(__METHOD__)
                ->fetchResultSet();
            foreach ($result as $row) {
                /** @var stdClass $row */
                $groups[(int) $row->ug_user][] = $row->ug_group;
            }
        }
        return $groups;
    }

    /**
     * The output fields for a user row.
     *
     * @param string[] $groups
     * @return array<string,mixed>
     */
    private static function item(stdClass $row, array $groups): array
    {
        sort($groups);
        return array_combine(self::FIELDS, [
            (int) $row->user_id,
            $row->user_name,
            (string) $row->user_email,
            self::timestamp($row->user_registration),
            implode(', ', $groups),
            (int) $row->user_editcount,
            (string) $row->user_real_name,
        ]);
    }
}
