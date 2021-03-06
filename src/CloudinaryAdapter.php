<?php

namespace Enl\Flysystem\Cloudinary;

use Cloudinary\Api;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\StreamedTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;

class CloudinaryAdapter extends AbstractAdapter
{
    /** @var ApiFacade */
    private $api;

    use NotSupportingVisibilityTrait; // We have no visibility for paths, due all of them are public

    use StreamedTrait; // We have no streaming in Cloudinary API, so we need this polyfill

    use StreamedCopyTrait;

    public function __construct(ApiFacade $api, $prefix = null)
    {
        $this->api = $api;
        $this->setPathPrefix($prefix);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        return $this->normalizeMetadata($this->api->upload($path, $contents));
    }

    public function applyPathPrefix($path)
    {
        $path = $this->removeExtension($path);

        return parent::applyPathPrefix($path);
    }

    private function removeExtension($path)
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $path = $ext ? substr($path, 0, -1 + strlen($ext) * -1) : $path; //remove extension

        return $path;
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        // Cloudinary does not distinguish create and update
        return $this->write($path, $contents, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        return (bool)$this->api->rename($path, $newpath);
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        $response = $this->api->delete_resources([$path]);

        return $response['deleted'][$path] === 'deleted';
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $dirname = $this->applyPathPrefix($dirname);

        $response = $this->api->delete_resources_by_prefix(rtrim($dirname, '/') . '/');

        return is_array($response['deleted']);
    }

    /**
     * Create a directory.
     * Cloudinary creates folders implicitly when you upload file with name 'path/file' and it has no API for folders
     * creation. So that we need to just say "everything is ok, go on!".
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $dirname = $this->applyPathPrefix($dirname);

        return [
            'path' => rtrim($dirname, '/') . '/',
            'type' => 'dir',
        ];
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);

        return !is_null($this->api->getMimetype($path));
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        if ($response = $this->readStream($path)) {
            return ['contents' => stream_get_contents($response['stream']), 'path' => $response['path']];
        }

        return false;
    }

    /**
     * @param $path
     *
     * @return array|bool
     */
    public function readStream($path)
    {
        $path = $this->applyPathPrefix($path);

		$seekableStream = fopen('php://temp', 'r+');
		stream_copy_to_stream($this->api->content($path), $seekableStream);
		rewind($seekableStream);

        return [
            'stream' => $seekableStream,
            'path' => $path,
        ];
    }

    /**
     * List contents of a directory.
     * Unfortunately, Cloudinary does not support non recursive directory scan
     * because they treat filename prefixes as folders.
     *
     * @param string $directory
     * @param bool $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->applyPathPrefix($directory);

        return $this->doListContents($directory);
    }

    private function doListContents($directory = '', array $storage = [])
    {
        $options = ['prefix' => $directory, 'max_results' => 500, 'type' => 'upload'];
        if (array_key_exists('next_cursor', $storage)) {
            $options['next_cursor'] = $storage['next_cursor'];
        }

        $response = $this->api->resources($options);

        foreach ($response['resources'] as $resource) {
            ;
            $storage['files'][] = $this->normalizeMetadata($resource);
        }
        if (array_key_exists('next_cursor', $response)) {
            $storage['next_cursor'] = $response['next_cursor'];

            return $this->doListContents($directory, $storage);
        }

        return $storage['files'];
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);
        $apiMetadata = $this->api->getMetadata($path);

        if (!$apiMetadata) {
            return null;
        }

        return [
            'hash' => str_replace('"', '', $apiMetadata['Etag']), //somehow there are double "'s
            'type' => 'file',
            'path' => $path,
            'size' => array_key_exists('Content-Length', $apiMetadata) ? $apiMetadata['Content-Length'] : false,
            'timestamp' => array_key_exists('Last-Modified', $apiMetadata) ? strtotime($apiMetadata['Last-Modified']) : false,
        ];
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return [
            'mimetype' => $this->api->getMimetype($path)
        ];
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    private function normalizeMetadata($resource)
    {
        return !$resource instanceof \ArrayObject && !is_array($resource) ? false : [
            'type' => 'file',
            'path' => $resource['public_id'],
            'size' => array_key_exists('bytes', $resource) ? $resource['bytes'] : false,
            'timestamp' => array_key_exists('created_at', $resource) ? strtotime($resource['created_at']) : false,
        ];
    }
}
