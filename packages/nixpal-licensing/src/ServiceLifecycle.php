<?php

namespace Nixpal\Licensing;

/**
 * When a Blesta service gets its license row, and which key it keeps.
 *
 * Blesta creates the service at order time with status pending (in_review while
 * the order is not accepted) and calls the module's addService() then, and again
 * when the service is activated; that second call carries only the edit's vars,
 * not the service's fields, so on its own the module would issue a new key.
 *
 *  - The key is issued at order time (the client can see it), but the license row,
 *    which is what zlic answers from, is created only once the service is active
 *    (or suspended): an unpaid order never answers Active. Same as WHMCS, where the
 *    Licensing Addon creates mod_licensing only on module create.
 *  - On activation the plugin hands the service's existing key to addService()
 *    (Services.editBefore), so the key the client already has is the one that works.
 */
final class ServiceLifecycle
{
    /**
     * Keys the plugin handed to addService() in this request: key => service id.
     * Kept here, out of the request vars, so a client cannot claim the exemption
     * from the uniqueness check by posting a service id.
     *
     * @var array<string,int>
     */
    private static $handedOver = [];

    /** Records that $key is being handed back to its own service $serviceId on activation. */
    public static function handOver($key, $serviceId)
    {
        self::$handedOver[(string) $key] = (int) $serviceId;
    }

    /** The service a key was handed over to in this request, or null. */
    public static function handedOverTo($key)
    {
        return self::$handedOver[(string) $key] ?? null;
    }

    /** For tests. */
    public static function resetHandOvers()
    {
        self::$handedOver = [];
    }

    /**
     * License status for a service's row, or null while the service must not have one.
     *
     * @param  string  $serviceStatus  Blesta services.status
     * @return string|null
     */
    public static function rowStatus($serviceStatus)
    {
        switch ($serviceStatus) {
            case 'active':
                return License::ACTIVE;
            case 'suspended':
                return License::SUSPENDED;
            default: // pending, in_review, canceled (never activated)
                return null;
        }
    }

    /**
     * The key to put into the edit's vars when a pending service is activated, or
     * null to leave the vars alone.
     *
     * @param  string  $currentStatus  the service's status before the edit
     * @param  array  $vars  the Services::edit() vars
     * @param  string  $existingKey  the service's license_key field ('' when none)
     * @return string|null
     */
    public static function keyForActivation($currentStatus, array $vars, $existingKey)
    {
        if (($vars['status'] ?? null) !== 'active'
            || !in_array($currentStatus, ['pending', 'in_review'], true)
            || trim((string) ($vars['license_key'] ?? '')) !== ''
            || (string) $existingKey === ''
        ) {
            return null;
        }

        return (string) $existingKey;
    }

    /**
     * The service's license_key field ('' when none).
     *
     * @param  object  $service  a Blesta service with ->fields (key/value objects)
     */
    public static function serviceKey($service)
    {
        foreach ((array) ($service->fields ?? []) as $field) {
            if (($field->key ?? null) === 'license_key') {
                return (string) $field->value;
            }
        }

        return '';
    }

    /**
     * The license row of an active or suspended service, created from its key if
     * it has none yet. Shared by the plugin's Services events and the module, so the
     * rule lives in one place.
     *
     * @param  object  $service  a Blesta service (id, status, fields)
     * @param  object  $licenses  getByService($id), keyIsFree($key, $serviceId), add(array)
     * @return object|false the row, or false when the service must not / cannot have one
     */
    public static function ensureRow($service, $licenses)
    {
        if (empty($service->id)) {
            return false;
        }
        if ($row = $licenses->getByService($service->id)) {
            return $row;
        }
        $status = self::rowStatus($service->status ?? '');
        $key = self::serviceKey($service);
        if ($status === null || $key === '' || !$licenses->keyIsFree($key, $service->id)) {
            return false;
        }
        $licenses->add(['service_id' => $service->id, 'license_key' => $key, 'status' => $status]);

        return $licenses->getByService($service->id);
    }
}
