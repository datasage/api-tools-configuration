<?php

namespace Laminas\ApiTools\Configuration;

use Laminas\Config\Writer\WriterInterface as ConfigWriter;
use Laminas\Stdlib\ArrayUtils;
use stdClass;
use Traversable;

use function array_key_exists;
use function array_merge;
use function array_shift;
use function explode;
use function file_exists;
use function function_exists;
use function gettype;
use function ini_get;
use function is_array;
use function is_object;
use function opcache_invalidate;
use function sprintf;

class ConfigResource
{
    /** @var array */
    protected $config;

    /**
     * File to which to write configuration
     *
     * @var string
     */
    protected $fileName;

    /**
     * Whether or not OpCache is enabled
     *
     * @var bool
     */
    protected $opcacheEnabled = false;

    /** @var ConfigWriter */
    protected $writer;

    /**
     * @param string $fileName
     */
    public function __construct(array $config, $fileName, ConfigWriter $writer)
    {
        $this->opcacheEnabled = function_exists('opcache_invalidate') && ini_get('opcache.enable');

        $this->config   = $config;
        $this->fileName = $fileName;
        $this->writer   = $writer;
    }

    /**
     * Allow patching one or more key/value pairs
     *
     * Expects data to be in the form of key/value pairs
     *
     * @param  array|stdClass|Traversable $data
     * @param  bool $tree
     * @return array
     */
    public function patch($data, $tree = false)
    {
        if ($data instanceof Traversable) {
            $data = ArrayUtils::iteratorToArray($data);
        }

        if ($data instanceof stdClass) {
            $data = (array) $data;
        }

        // Update configuration from dot-separated key/value pairs
        if (! $tree) {
            $patchValues = [];
            foreach ($data as $key => $value) {
                $this->createNestedKeyValuePair($patchValues, $key, $value);
            }
        } else {
            $patchValues = $data;
        }

        // Get local config file
        $localConfig = [];
        if (file_exists($this->fileName)) {
            $localConfig = include $this->fileName;
            if (! is_array($localConfig)) {
                $localConfig = [];
            }
        }
        $localConfig = ArrayUtils::merge($localConfig, $patchValues);

        // Write to configuration file
        $this->writer->toFile($this->fileName, $localConfig);
        $this->invalidateCache($this->fileName);

        // Reseed configuration
        $this->config = $localConfig;

        // Return written values
        return $data;
    }

    /**
     * Patch a single (potentially nested) key in the config file
     *
     * @param  string $key
     * @param  mixed $value
     * @return array
     */
    public function patchKey($key, $value)
    {
        // Get local config file
        $config = [];
        if (file_exists($this->fileName)) {
            $config = include $this->fileName;
            if (! is_array($config)) {
                $config = [];
            }
        }
        $config = $this->replaceKey($key, $value, $config);

        // Write to configuration file
        $this->writer->toFile($this->fileName, $config);
        $this->invalidateCache($this->fileName);

        // Reseed configuration
        $this->config = $config;

        // Return written values
        return $config;
    }

    /**
     * Overwrite configuration
     *
     * Used by consumers only; takes the configuration data and writes it verbatim.
     *
     * @return array
     */
    public function overWrite(array $data)
    {
        $this->writer->toFile($this->fileName, $data);
        $this->invalidateCache($this->fileName);

        // Reseed configuration
        $this->config = $data;

        return $data;
    }

    /**
     * Fetch all configuration values
     *
     * Flattens nested configuration to dot-separated key/value pairs and returns them.
     *
     * @param  bool $tree
     * @return array
     */
    public function fetch($tree = false)
    {
        // If requested as a tree, return as-is
        if ($tree) {
            return $this->config;
        }

        // Collapse to key/value pairs -- meaning to dot-separated nested keys
        return $this->traverseArray($this->config);
    }

    /**
     * Replace a nested key
     *
     * First invocation should pass a dot-separated string representing a
     * nested key.
     *
     * This value will be exploded to a list of keys, and the first element of
     * the list will be compared against the provided configuration array; the
     * method will recurse as necessary in order to replace the key.
     *
     * @param  string|array $keys
     * @param  mixed $value
     * @return array
     */
    public function replaceKey($keys, $value, array $config)
    {
        if (! is_array($keys)) {
            $keys = explode('.', $keys);
        }

        $key = array_shift($keys);

        // If no more keys, overwrite and return
        if (! $keys) {
            $config[$key] = $value;
            return $config;
        }

        // If key does not exist, or the current value is not an associative
        // array, create nested set and return
        if (
            ! isset($config[$key])
            || ! ArrayUtils::isHashTable($config[$key])
        ) {
            $config[$key] = $this->replaceKey($keys, $value, []);
            return $config;
        }

        // Otherwise, recurse through it
        $config[$key] = $this->replaceKey($keys, $value, $config[$key]);
        return $config;
    }

    /**
     * Delete a key from the configuration array
     *
     * $key may be either an array of keys or a dot-separated set of keys.
     *
     * @param  array|string $keys
     * @return array
     */
    public function deleteKey($keys)
    {
        // Get local config file
        $config = [];
        if (file_exists($this->fileName)) {
            $config = include $this->fileName;
            if (! is_array($config)) {
                $config = [];
            }
        }

        if (! is_array($keys)) {
            $keys = explode('.', $keys);
        }

        if (empty($keys)) {
            return $config;
        }

        $this->deleteByKey($config, $keys);
        $this->writer->toFile($this->fileName, $config);
        $this->invalidateCache($this->fileName);

        // Reseed configuration
        $this->config = $config;

        return $config;
    }

    /**
     * Traverse a nested array and flatten to dot-separated key/value pairs
     *
     * @param  string $currentKey Current key, if called recursively
     * @return array
     */
    public function traverseArray(array $array, $currentKey = '')
    {
        $flattened = [];
        foreach ($array as $key => $value) {
            $targetKey = '' === $currentKey ? $key : $currentKey . '.' . $key;
            if (is_array($value)) {
                $value     = $this->traverseArray($value, $targetKey);
                $flattened = array_merge($flattened, $value);
                continue;
            }

            $flattened[$targetKey] = $value;
        }
        return $flattened;
    }

    /**
     * Create a nested key/value pair from a dot-separated key value pair
     *
     * Extracts the nested pair into the array provided in $patchValues
     *
     * @param array $patchValues
     * @param string $key
     * @param mixed $value
     * @throws Exception\InvalidArgumentException
     * @return void
     */
    public function createNestedKeyValuePair(&$patchValues, $key, $value)
    {
        if (! is_array($patchValues)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects the $patchValues argument to be an array; received %s',
                __METHOD__,
                is_object($patchValues) ? $patchValues::class : gettype($patchValues)
            ));
        }

        $this->extractAndSet(explode('.', $key), $value, $patchValues);
    }

    /**
     * Recursively extract keys into a nested array
     *
     * @param string $value
     * @param array $array
     */
    protected function extractAndSet(array $keys, $value, &$array): void
    {
        $key = array_shift($keys);
        if ($keys) {
            if (! isset($array[$key]) || ! is_array($array[$key])) {
                $array[$key] = [];
            }
            $reference = &$array[$key];
            $this->extractAndSet($keys, $value, $reference);
            return;
        }
        $array[$key] = $value;
    }

    /**
     * Delete a nested key/value pair in an array
     *
     * @param array $array
     */
    protected function deleteByKey(&$array, array $keys): void
    {
        $key = array_shift($keys);
        if (! is_array($array) || ! array_key_exists($key, $array)) {
            return;
        }

        if (! $keys) {
            unset($array[$key]);
            return;
        }
        $this->deleteByKey($array[$key], $keys);
    }

    /**
     * Invalidate the opcache for a given file
     *
     * @param string $filename
     */
    protected function invalidateCache($filename): void
    {
        if (! $this->opcacheEnabled) {
            return;
        }

        opcache_invalidate($filename, true);
    }
}
