<?php

namespace ReactFileWatcher;

interface FileWatcherInterface
{
    function Watch(array $pathsToWatch, \Closure $closure);
}