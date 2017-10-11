<?php


namespace Enl\Flysystem\Cloudinary\Test;

use Enl\Flysystem\Cloudinary\CloudinaryAdapter;
use League\Flysystem\Config;

use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery\MockInterface;

/**
 * Class CloudinaryAdapterTest
 * @package Enl\Flysystem\Cloudinary\Test
 */
class CloudinaryAdapterTest extends MockeryTestCase
{
    private function getApiMock()
    {
        return m::mock('Enl\Flysystem\Cloudinary\ApiFacade');
    }

    public function adapterProvider()
    {
        $mock = $this->getApiMock();

        return [
            [new CloudinaryAdapter($mock), $mock]
        ];
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function writeShouldReturnNormalizedMetadataOnSuccess(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $path = 'test-path';
        $api->shouldReceive('upload')->withAnyArgs()->andReturn(['public_id' => $path, 'bytes' => 123123]);
        $response = $cloudinary->write($path, 'contents', new Config());

        $this->assertArrayHasKey('path', $response);
        $this->assertEquals($path, $response['path']);
        $this->assertArrayHasKey('size', $response);
        $this->assertEquals(123123, $response['size']);
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function updateShouldReturnNormalizedMetadataOnSuccess(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $path = 'test-path';
        $api->shouldReceive('upload')->withAnyArgs()->andReturn(['public_id' => $path, 'bytes' => 123123]);
        $response = $cloudinary->update($path, 'contents', new Config());

        $this->assertArrayHasKey('path', $response);
        $this->assertEquals($path, $response['path']);
        $this->assertArrayHasKey('size', $response);
        $this->assertEquals(123123, $response['size']);
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function writeStreamShouldReturnMetadataOnSuccess(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $path = 'test-path';
        $api->shouldReceive('upload')->withAnyArgs()->andReturn(['public_id' => $path]);
        $response = $cloudinary->writeStream($path, tmpfile(), new Config());

        $this->assertArrayHasKey('path', $response);
        $this->assertEquals($path, $response['path']);
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function renameShouldReturnTrueOnSuccess(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $api->shouldReceive('rename')->withArgs(['old', 'new'])->once()->andReturn(['public_id' => 'new']);
        $this->assertTrue($cloudinary->rename('old', 'new'));
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function deleteShouldReturnFalseOnFailure(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $api->shouldReceive('delete_resources')
            ->with(['file'])
            ->once()
            ->andReturn(['deleted' => ['file' => 'not_found']]);
        $this->assertFalse($cloudinary->delete('file'));
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function deleteShouldReturnTrueOnSuccess(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $api->shouldReceive('delete_resources')
            ->with(['file'])
            ->once()->andReturn(['deleted' => ['file' => 'deleted']]);
        $this->assertTrue($cloudinary->delete('file'));
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function readShouldReturnArrayOnSuccess(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $api->shouldReceive('content')->with('file')->once()->andReturn(tmpfile());
        $this->assertEquals(['path' => 'file', 'contents' => ''], $cloudinary->read('file'));
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function readStreamShouldReturnArrayOnSuccess(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $api->shouldReceive('content')->with('file')->once()->andReturn(tmpfile());

        $response = $cloudinary->readStream('file');
        $this->assertInternalType('array', $response);
        $this->assertEquals('file', $response['path']);
        $this->assertInternalType('resource', $response['stream']);
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function listContentsShouldReturnAllContents(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $request = ['prefix' => '', 'max_results' => 500, 'type' => 'upload'];
        $expected = [
            ['public_id' => 'test-1'],
            ['public_id' => 'test-2'],
            ['public_id' => 'test-3']
        ];

        $api->shouldReceive('resources')
            ->once()
            ->with($request)
            ->andReturn(['resources' => array_slice($expected, 0, 2, false), 'next_cursor' => 'cursor']);

        $api->shouldReceive('resources')
            ->once()
            ->with(array_merge($request, ['next_cursor' => 'cursor']))
            ->andReturn(['resources' => array_slice($expected, 2)]);

        $actual = $cloudinary->listContents();
        $this->assertEquals(count($expected), count($actual));
    }

    /**
     * @param CloudinaryAdapter $cloudinary
     * @param MockInterface $api
     * @test
     * @dataProvider adapterProvider
     */
    public function listContentsShouldReturnNormalizedMetadata(CloudinaryAdapter $cloudinary, MockInterface $api)
    {
        $public_id = 'test';
        $bytes = 123123;
        $created_at = date('Y-m-d H:i:s');

        $api->shouldReceive('resources')->andReturn(['resources' => [compact('public_id', 'bytes', 'created_at')]]);

        $expected = [
            'type' => 'file',
            'path' => $public_id,
            'size' => $bytes,
            'timestamp' => strtotime($created_at)
        ];

        $actual = $cloudinary->listContents()[0];

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual);
            $this->assertEquals($value, $actual[$key]);
        }
    }


    public function metadataProvider()
    {
        return [
            ['getMetadata'],
            ['getTimestamp'],
            ['getSize'],
            ['has'],
        ];
    }

    /**
     * @dataProvider  metadataProvider
     * @test
     */
    public function testMetadataCallsSuccess($method)
    {
        list ($cloudinary, $api) = $this->adapterProvider()[0];

        $public_id = 'file';
        $bytes = 123123;
        $created_at = date('Y-m-d H:i:s');

        /** @var MockInterface $api */
        $api->shouldReceive('resource')->once()->andReturn(compact('public_id', 'bytes', 'created_at'));

        $actual = $cloudinary->{$method}('one', 'two');


        $expected = [
            'type' => 'file',
            'path' => $public_id,
            'size' => $bytes,
            'timestamp' => strtotime($created_at)
        ];

        $this->assertInternalType('array', $actual);

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual);
            $this->assertEquals($value, $actual[$key]);
        }
    }

    /**
     * @test
     * @expectedException \LogicException
     */
    public function getVisibilityShouldNotBeSupported()
    {
        list($adapter) = $this->adapterProvider()[0];
        /** @var CloudinaryAdapter $adapter */
        $adapter->setVisibility('path', 'visibility');
    }

    /**
     * @test
     * @expectedException \LogicException
     */
    public function setVisibilityShouldNotBeSupported()
    {
        list($adapter) = $this->adapterProvider()[0];
        /** @var CloudinaryAdapter $adapter */
        $adapter->getVisibility('path');
    }


    public function createDirProvider()
    {
        return [
            ['path', ['path' => 'path/', 'type' => 'dir']],
            ['path/', ['path' => 'path/', 'type' => 'dir']],
        ];
    }

    /**
     * @test
     * @dataProvider createDirProvider
     */
    public function createDirShouldAlwaysReturnSuccess($path, $expected)
    {
        /** @var CloudinaryAdapter $adapter */
        list($adapter) = $this->adapterProvider()[0];

        $this->assertEquals($expected, $adapter->createDir($path, new Config()));
    }

    /**
     * @test
     * @dataProvider adapterProvider
     */
    public function deleteDirShouldReturnTrueIfSuccess(CloudinaryAdapter $adapter, MockInterface $api)
    {
        $api->shouldReceive('delete_resources_by_prefix')->with('path/')->andReturn(['deleted' => []]);

        $this->assertTrue($adapter->deleteDir('path'));
        $this->assertTrue($adapter->deleteDir('path/'));
    }

}
