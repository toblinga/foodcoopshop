<?php
declare(strict_types=1);

namespace App\Services;

use Cake\Core\Configure;

/**
 * Settings of the contacts API / CardDAV address book (admin > settings):
 * which members are visible and whether only members that ordered recently are included.
 */
class ContactsSettingsService
{
    public const MODE_CONSENT = 'consent';
    public const MODE_OPT_OUT = 'opt_out';
    public const MODE_ALL = 'all';
    public const MODES = [self::MODE_CONSENT, self::MODE_OPT_OUT, self::MODE_ALL];

    /**
     * consent (default): only members that agreed; opt_out: everybody except members that objected; all: every active member.
     */
    public static function getMode(): string
    {
        $mode = Configure::read('appDb.FCS_CONTACTS_MODE');
        if (!in_array($mode, self::MODES, true)) {
            $mode = Configure::read('app.contactsMode'); // before the setting existed it was a config value
        }
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_CONSENT;
    }

    /**
     * Only members with an order (pickup day) in the last X months; 0 = every active member.
     */
    public static function getOrderedWithinMonths(): int
    {
        return max(0, (int) Configure::read('appDb.FCS_CONTACTS_ORDERED_WITHIN_MONTHS'));
    }
}
