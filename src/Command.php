<?php

declare(strict_types=1);

namespace MwCli;

use DateTimeImmutable;
use DateTimeZone;
use ManualLogEntry;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use StatusValue;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

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

    /**
     * Subcommand names with a hyphen, e.g. ['addGroup' => 'add-group'] for `mw user add-group`.
     * Other camelCase methods are subcommands of several words: categoryList is
     * `mw page category list`.
     *
     * @var array<string,string>
     */
    public const NAMES = [];

    private ?User $performer = null;

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
        $this->performer ??= $this->findPerformer();
        return $this->performer;
    }

    /**
     * MediaWiki's message for a status that isn't OK, in English like mw's own messages.
     * The user language stays as configured for everything else (e.g. formatted results).
     *
     * A template, not StatusValue<mixed>: StatusValue only became generic after 1.43.
     *
     * @template T of StatusValue
     * @param T $status
     */
    protected function statusText(StatusValue $status): string
    {
        $context = new DerivativeContext(RequestContext::getMain());
        $context->setLanguage('en');
        return $this->services()->getFormatterFactory()->getStatusFormatter($context)
            ->getMessage($status)->text();
    }

    private function findPerformer(): User
    {
        $name = $this->cli->flag('user');
        if ($name === null) {
            // No service replaces this in 1.43, and it isn't deprecated.
            return User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true])
                ?? Runner::error("Can't create the '" . User::MAINTENANCE_SCRIPT_USER . "' user.");
        }
        return $this->registeredUser($name) ?? Runner::error("Invalid user '$name'.");
    }

    /**
     * The registered user with this name, or with this ID if no user has that name.
     */
    protected function registeredUser(string $name): ?User
    {
        // The lookup, unlike UserFactory::newFromId(), returns null for IDs that don't exist. From
        // the primary, so a lagging replica can't hide a user that was just created.
        $lookup = $this->services()->getUserIdentityLookup();
        $user = $lookup->getUserIdentityByName($name, IDBAccessObject::READ_LATEST);
        if (!$user?->isRegistered() && ctype_digit($name)) {
            $user = $lookup->getUserIdentityByUserId((int) $name, IDBAccessObject::READ_LATEST);
        }
        return $user?->isRegistered()
            ? $this->services()->getUserFactory()->newFromUserIdentity($user)
            : null;
    }

    /**
     * A new log entry. MediaWiki 1.44 moved ManualLogEntry into MediaWiki\Logging and keeps the
     * old global name only as a deprecated alias, while 1.43 has only the global name. The
     * return type is in the docblock only, so nothing at runtime depends on the alias.
     *
     * @return ManualLogEntry
     */
    // phpcs:ignore Syde.Functions.ReturnTypeDeclaration.NoReturnType -- See the docblock.
    protected static function logEntry(string $type, string $action)
    {
        $namespaced = 'MediaWiki\\Logging\\ManualLogEntry';
        /** @var class-string<ManualLogEntry> $class */
        $class = class_exists($namespaced) ? $namespaced : 'ManualLogEntry';
        /** @var ManualLogEntry $entry */
        $entry = new $class($type, $action);
        return $entry;
    }

    /**
     * A database timestamp as 'Y-m-d H:i:s' in UTC, or null.
     */
    protected static function timestamp(?string $timestamp): ?string
    {
        // PHP reads YmdHis (MySQL, SQLite) and PostgreSQL timestamps, which may carry an offset.
        $utc = new DateTimeZone('UTC');
        return $timestamp === null ? null : (new DateTimeImmutable($timestamp, $utc))
            ->setTimezone($utc)->format('Y-m-d H:i:s');
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
     * Applies --limit to the query, if given.
     *
     * @param array<string,string|bool> $assocArgs
     */
    protected function applyLimit(SelectQueryBuilder $query, array $assocArgs): void
    {
        $limit = $this->limit($assocArgs);
        if ($limit !== null) {
            $query->limit($limit);
        }
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
