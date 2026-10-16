<?php

namespace App\Classes\Extension;

use App\Models\Extension as ModelsExtension;
use App\Models\Gateway;
use App\Models\Server;
use RuntimeException;

/**
 * Base class for extensions
 */
class Extension
{
    public function __construct(public $config = []) {}

    /**
     * Its settings were given (ExtensionHelper::getExtension with a server's, a gateway's…), even empty: they are this
     * instance's only settings. Without them it falls back to its extension's one record (closeout: an empty server
     * took the first server's credentials, an account on the wrong WHM with myip's 6–7).
     */
    public bool $explicitConfig = false;

    /**
     * Get a configuration value
     *
     * @param  string  $key
     * @return mixed
     */
    public function config($key)
    {
        if (empty($this->config) && !$this->explicitConfig) {
            // Check from which type its being called
            $type = debug_backtrace()[1]['class'];
            $type = str_replace('MyCS\Extensions\\', '', $type);
            $type = str_replace('\\' . class_basename(static::class), '', $type);
            if (in_array($type, ['Servers', 'Gateways'])) {
                $type = substr($type, 0, -1);
                $type = ($type == 'Gateway') ? Gateway::class : Server::class;
                // Only when there is one: with several servers of a module, which one is never guessed.
                $records = $type::where('extension', class_basename(static::class))->limit(2)->get();
                if ($records->count() !== 1) {
                    throw new RuntimeException(class_basename(static::class) . ': ' . ($records->isEmpty() ? 'no record to read its settings from' : 'several records, the settings must be given') . '.');
                }
                $this->config = $records->first()->settings->pluck('value', 'key')->toArray();
            } else {
                $this->config = ModelsExtension::where('extension', class_basename(static::class))->first()->settings->pluck('value', 'key')->toArray();
            }
        }

        // A pasted host, key or token often carries a space or line break, which an API answers as a bad credential.
        $value = $this->config[$key] ?? null;

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Get the configuration fields for the extension
     *
     * @param  array  $values  The current values of the configuration (is empty on first load)
     * @return array
     */
    public function getConfig($values = [])
    {
        return [];
    }

    /**
     * Called when the extension is installed for the first time
     * If the extension type is server or gateway, it will be called when the first server or gateway is created
     *
     * @return void
     */
    public function installed() {}

    /**
     * Called when the extension is uninstalled
     * If the extension type is server or gateway, it will be called when the last server or gateway is deleted
     *
     * @return void
     */
    public function uninstalled() {}

    /**
     * Called when the extension is updated
     * This is called when the extension is updated to a new version
     *
     * @param  string  $oldVersion  The old version of the extension
     * @return void
     */
    public function upgraded($oldVersion = null) {}

    /**
     * Called every request to the extension (if the extension is enabled)
     *
     * @return void
     */
    public function boot() {}
}
