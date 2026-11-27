<?php

declare(strict_types=1);

namespace MwCli;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LikeValue;

/**
 * Base for classes in src/Command/. The file name is the command name (SearchReplace.php is
 * `mw search-replace`). Public methods are subcommands, or the class has __invoke() and none.
 * Each takes (array $args, array $assocArgs) and documents its options in its docblock, see
 * Synopsis.
 */
abstract class Command
{
    /** False for commands that run without loading MediaWiki. */
    public const NEEDS_WIKI = true;

    /**
     * Descriptions of subcommand groups for help, e.g. ['category' => '…'] for `page category`.
     *
     * @var array<string,string>
     */
    public const GROUPS = [];

    public function __construct(protected Runner $cli)
    {
    }

    protected function services(): MediaWikiServices
    {
        return MediaWikiServices::getInstance();
    }

    /**
     * The user from --user (name, or ID if no user has that name), or MediaWiki's
     * "Maintenance script" system user.
     */
    public function performer(): User
    {
        $name = $this->cli->flag('user');
        if ($name === null) {
            // No service replaces this in 1.43, and it isn't deprecated.
            return User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true])
                ?? Runner::error("Can't create the '" . User::MAINTENANCE_SCRIPT_USER . "' user.");
        }
        // The lookup, unlike UserFactory::newFromId(), returns null for IDs that don't exist.
        $lookup = $this->services()->getUserIdentityLookup();
        $user = $lookup->getUserIdentityByName($name);
        if (!$user?->isRegistered() && ctype_digit($name)) {
            $user = $lookup->getUserIdentityByUserId((int) $name);
        }
        if ($user === null || !$user->isRegistered()) {
            Runner::error("Invalid user '$name'.");
        }
        return $this->services()->getUserFactory()->newFromUserIdentity($user);
    }

    /**
     * A namespace index from a number or a (localized) namespace name, or fails.
     */
    protected function namespace(string $name): int
    {
        // getNsIndex() would read '' as the main namespace.
        if ($name === '') {
            Runner::error('--namespace needs a value.');
        }
        $index = ctype_digit($name)
            ? (int) $name
            : $this->services()->getContentLanguage()->getNsIndex(strtr($name, ' ', '_'));
        if ($index === false || !$this->services()->getNamespaceInfo()->exists($index)) {
            Runner::error("Unknown namespace '$name'.");
        }
        return $index;
    }

    /**
     * --limit as a number, or null without it. Fails unless it's a positive integer.
     *
     * @param array<string,string|bool> $assocArgs
     */
    protected function limit(array $assocArgs): ?int
    {
        if (!isset($assocArgs['limit'])) {
            return null;
        }
        $limit = (string) $assocArgs['limit'];
        if (!ctype_digit($limit) || (int) $limit < 1) {
            Runner::error('--limit must be a positive number.');
        }
        return (int) $limit;
    }

    /**
     * A condition for titles in this DB key field that start with the prefix, written as on a page.
     */
    protected function titlePrefix(
        IReadableDatabase $db,
        string $field,
        string $prefix,
        int $namespace
    ): IExpression {

        $prefix = strtr($prefix, ' ', '_');
        if ($this->services()->getNamespaceInfo()->isCapitalized($namespace)) {
            $prefix = $this->services()->getContentLanguage()->ucfirst($prefix);
        }
        return $db->expr($field, IExpression::LIKE, new LikeValue($prefix, $db->anyString()));
    }

    /**
     * The category title for a name with or without the (localized) namespace prefix.
     */
    protected function category(string $name): Title
    {
        $factory = $this->services()->getTitleFactory();
        // A leading colon, as in [[:Category:Foo]], is allowed.
        $text = ltrim($name, ':');
        $title = $factory->newFromText($text, NS_CATEGORY);
        if ($title && $title->getNamespace() !== NS_CATEGORY) {
            // "Help:Foo" names the category Category:Help:Foo, not the help page.
            $title = $factory->makeTitleSafe(NS_CATEGORY, $text);
        }
        return $title ?? Runner::error("Invalid category '$name'.");
    }
}
