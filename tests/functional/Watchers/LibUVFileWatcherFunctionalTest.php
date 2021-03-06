<?php

use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use React\EventLoop\ExtUvLoop;
use React\EventLoop\LoopInterface;
use ReactFileWatcher\Exceptions\RecursiveWatchNotImplemented;
use ReactFileWatcher\FileWatcherFactory;
use ReactFileWatcher\PathObjects\PathWatcher;
use ReactFileWatcher\Watchers\LibUVFileWatcher;
use function PHPUnit\Framework\MockObject\MockBuilder;

const TEMP_DIR = "/tmp/react-watcher-tests";

beforeEach(function() {
    // make sure that /tmp/react-watcher-tests dir exists
    if (!is_dir(TEMP_DIR)) {
        if (is_file(TEMP_DIR)) {
            throw new Exception("found a file in /tmp folder with the same name of the directory name for these tests ". TEMP_DIR);
        }
        // create the folder
        mkdir(TEMP_DIR);
        return;
    }
    // make sure that /tmp/react-watcher-tests dir is empty
    recursiveRemoveDirectory(TEMP_DIR);
    mkdir(TEMP_DIR);
});

afterAll(function() {
    // make sure that /tmp/react-watcher-tests dir is empty
    recursiveRemoveDirectory(TEMP_DIR);
});

it ("should watch for changes on recursive path watch", function() {
    // prepare file with first content.
    $tempFirstFileName = "1";
    $tempFirstFilePath = TEMP_DIR. "/$tempFirstFileName";
    file_put_contents($tempFirstFilePath, "first file: first insert");

    $newDir = "newDir";
    mkdir(TEMP_DIR."/$newDir");
    $tempSecondFileName = "newdir-1";
    $tempSecondFilePath = TEMP_DIR . "/$newDir/$tempSecondFileName";
    file_put_contents($tempSecondFilePath, "second file: first insert");

    $tempThirdFileName = "newdir-2";
    $tempThirdFilePath = TEMP_DIR . "/$newDir/$tempThirdFileName";
    file_put_contents($tempThirdFilePath, "third file: first insert");

    // prepare the event loop, the watcher and the path to watch
    $loop = new ExtUvLoop();
    $watcher = FileWatcherFactory::create($loop);
    expect(get_class($watcher))->toBe(LibUVFileWatcher::class);
    $pathWatcher = new PathWatcher(TEMP_DIR, true, []);

    // prepare a "mock" callback that will check that it has been fired exactly 3 times for file changed events
    $bothFileNames = [$tempFirstFileName, $tempSecondFileName, $tempThirdFileName];
    $shouldBeCalled = createStopLoopCallbackAfterFileChanged($this, $this->exactly(3), $loop, function($filename) use ($bothFileNames) {
        // the name of the changed file provided in the callback arg is right.
        expect($bothFileNames)->toContain($filename);
    });

    // set the watcher to watch the path. the unused variable $fsEvents is critical because without it the watcher won't work.
    $fsEvents = $watcher->Watch([$pathWatcher], $shouldBeCalled);
    // add a timer that will keep the loop running until the loop is stopped manually or until a timeout (interval)
    $loop->addTimer(1, function() use ($loop){
        $loop->stop();
    });

    // set a future callback to be called right after the loop starts - this is used to write something to the filesystem after the loop starts.
    $loop->futureTick(function () use ($loop, $tempFirstFilePath, $tempSecondFilePath, $tempThirdFilePath) {
        file_put_contents($tempFirstFilePath, "first file: second insert");
        file_put_contents($tempSecondFilePath, "second file: second insert");
        file_put_contents($tempThirdFilePath, "third file: second insert");
    });

    $loop->run();
});

it ("should invoke closure when file has modified", function() {
    // prepare file with first content.
    $tempFileName = "1";
    $tempFilePath = TEMP_DIR. "/$tempFileName";
    file_put_contents($tempFilePath, "first insert");

    // prepare the event loop, the watcher and the path to watch
    $loop = new ExtUvLoop();
    $watcher = FileWatcherFactory::create($loop);
    expect(get_class($watcher))->toBe(LibUVFileWatcher::class);
    $pathWatcher = new PathWatcher($tempFilePath, false, []);

    // prepare a "mock" callback that will check that it has been fired one time on event of file change.
    $shouldBeCalled = createStopLoopCallbackAfterFileChanged($this, $this->once(), $loop, function($filename) use ($tempFileName) {
        // the name of the changed file provided in the callback arg is right.
        expect($filename)->toBe($tempFileName);
    });

    // set the watcher to watch the path. the unused variable $fsEvents is critical because without it the watcher won't work.
    $fsEvents = $watcher->Watch([$pathWatcher], $shouldBeCalled);
    // add a timer that will keep the loop running until the loop is stopped manually or until a timeout (interval)
    $loop->addTimer(1, function() use ($loop){
        $loop->stop();
    });

    // set a future callback to be called right after the loop starts - this is used to write something to the filesystem after the loop starts.
    $loop->futureTick(function () use ($tempFilePath) {
        file_put_contents($tempFilePath, "second insert");
    });

    $loop->run();
});

it ("should not invoke closure when file has modified but is part of the ignore suffix list", function() {
    // prepare file with first content.
    $tempFileName = "1.txt";
    $tempFilePath = TEMP_DIR. "/$tempFileName";
    file_put_contents($tempFilePath, "first insert");

    // prepare the event loop, the watcher and the path to watch
    $loop = new ExtUvLoop();
    $watcher = FileWatcherFactory::create($loop);
    expect(get_class($watcher))->toBe(LibUVFileWatcher::class);
    $pathWatcher = new PathWatcher(TEMP_DIR, false, ["txt"]);

    // prepare a "mock" callback that check that it will never be called.
    $shouldBeCalled = createStopLoopCallbackAfterFileChanged($this, $this->never(), $loop, function($filename) {
    });

    // set the watcher to watch the path. the unused variable $fsEvents is critical because without it the watcher won't work.
    $fsEvents = $watcher->Watch([$pathWatcher], $shouldBeCalled);
    // add a timer that will keep the loop running until the loop is stopped manually or until a timeout (interval)
    $loop->addTimer(1, function() use ($loop){
        $loop->stop();
    });

    // set a future callback to be called right after the loop starts - this is used to write something to the filesystem after the loop starts.
    $loop->futureTick(function () use ($tempFilePath) {
        file_put_contents($tempFilePath, "second insert");
    });

    $loop->run();
});

it ("should invoke closure twice when 2 files were modified for the same PathWatcher", function() {
    // prepare files with first content.
    $firstTempFileName = "1";
    $secondTempFileName = "2";
    file_put_contents(TEMP_DIR."/$firstTempFileName", "first file: first insert");
    file_put_contents(TEMP_DIR."/$secondTempFileName", "second file: first insert");

    // prepare the event loop, the watcher and the path to watch
    $loop = new ExtUvLoop();
    $watcher = FileWatcherFactory::create($loop);
    expect(get_class($watcher))->toBe(LibUVFileWatcher::class);
    $pathWatcher = new PathWatcher(TEMP_DIR, false, []);

    // prepare a "mock" callback that will check that it has been fired exactly 2 times for file changed events
    $bothFileNames = [$firstTempFileName, $secondTempFileName];
    $shouldBeCalled = createStopLoopCallbackAfterFileChanged($this, $this->exactly(2), $loop, function($filename) use ($bothFileNames) {
        // the name of the changed file provided in the callback arg is right.
        expect($bothFileNames)->toContain($filename);
    });

    // set the watcher to watch the path. the unused variable $fsEvents is critical because without it the watcher won't work.
    $fsEvents = $watcher->Watch([$pathWatcher], $shouldBeCalled);
    // add a timer that will keep the loop running until the loop is stopped manually or until a timeout (interval)
    $loop->addTimer(1, function() use ($loop){
        $loop->stop();
    });

    // set a future callback to be called right after the loop starts - this is used to write something to the filesystem after the loop starts.
    $loop->futureTick(function () use ($loop, $firstTempFileName, $secondTempFileName) {
        file_put_contents(TEMP_DIR."/$firstTempFileName", "first file: second insert");
        file_put_contents(TEMP_DIR."/$secondTempFileName", "second file: second insert");
    });

    $loop->run();
});

it("should not invoke closure when path has not change", function() {
    // prepare files with first content.
    $firstTempFileName = "1";
    $secondTempFileName = "2";
    file_put_contents(TEMP_DIR."/$firstTempFileName", "first file: first insert");
    file_put_contents(TEMP_DIR."/$secondTempFileName", "second file: first insert");

    // prepare the event loop, the watcher and the path to watch
    $loop = new ExtUvLoop();
    $watcher = FileWatcherFactory::create($loop);
    expect(get_class($watcher))->toBe(LibUVFileWatcher::class);
    $pathWatcher = new PathWatcher(TEMP_DIR."/$firstTempFileName", false, []);

    // prepare a "mock" callback that will check that it has been fired exactly 2 times for file changed events
    $shouldBeCalled = createStopLoopCallbackAfterFileChanged($this, $this->never(), $loop, function($filename) {
    });

    // set the watcher to watch the path. the unused variable $fsEvents is critical because without it the watcher won't work.
    $fsEvents = $watcher->Watch([$pathWatcher], $shouldBeCalled);
    // add a timer that will keep the loop running until the loop is stopped manually or until a timeout (interval)
    $loop->addTimer(1, function() use ($loop){
        $loop->stop();
    });

    // set a future callback to be called right after the loop starts - this is used to write something to the filesystem after the loop starts.
    $loop->futureTick(function () use ($loop, $firstTempFileName, $secondTempFileName) {
        file_put_contents(TEMP_DIR."/$secondTempFileName", "second file: second insert");
    });

    $loop->run();
});

function createStopLoopCallbackAfterFileChanged($that, InvocationOrder $invocationOrder , LoopInterface $loop, $conditionToCheck) {
    $shouldBeCalled = $that->getMockBuilder(stdClass::class)
        ->setMethods(['__invoke'])
        ->getMock();

    $shouldBeCalled->expects($invocationOrder)
        ->method('__invoke')->will($that->returnCallback(function($filename) use ($loop, $conditionToCheck) {
            $conditionToCheck($filename);
            $loop->stop();
        }));
    return $shouldBeCalled;
}
// TODO: test the functionality of the watch