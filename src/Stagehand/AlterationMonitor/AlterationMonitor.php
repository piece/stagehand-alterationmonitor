<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */

/**
 * PHP version 5.3
 *
 * Copyright (c) 2009, 2012 KUBO Atsuhiro <kubo@iteman.jp>,
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    Stagehand_AlterationMonitor
 * @copyright  2009, 2012 KUBO Atsuhiro <kubo@iteman.jp>
 * @license    http://www.opensource.org/licenses/bsd-license.php  New BSD License
 * @version    Release: @package_version@
 * @since      File available since Release 1.0.0
 */

namespace Stagehand\AlterationMonitor;

use Symfony\Component\Finder\Finder;

/**
 * A file and directory alteration monitor.
 *
 * @package    Stagehand_AlterationMonitor
 * @copyright  2009, 2012 KUBO Atsuhiro <kubo@iteman.jp>
 * @license    http://www.opensource.org/licenses/bsd-license.php  New BSD License
 * @version    Release: @package_version@
 * @since      Class available since Release 1.0.0
 */
class AlterationMonitor
{
    const SCAN_INTERVAL_MIN = 5;

    protected $directories;
    protected $callback;
    protected $scanInterval = self::SCAN_INTERVAL_MIN;
    protected $isFirstTime = true;
    protected $currentElements = array();
    protected $previousElements = array();
    protected $eventQueue = array();

    /**
     * Sets one or more target directories and a callback to the properties.
     *
     * @param array    $directories
     * @param callback $callback
     */
    public function __construct($directories, $callback)
    {
        $this->directories = $directories;
        $this->callback = $callback;
    }

    /**
     * Watches for changes in the target directories and invokes the callback when
     * changes are detected.
     */
    public function monitor()
    {
        while (true) {
            $this->waitForChanges();
            $this->invokeCallback();
        }
    }

    /**
     * Detects any changes of a file or directory immediately.
     *
     * @param string $file
     * @throws \Stagehand\AlterationMonitor\Exception
     */
    public function detectChanges($file)
    {
        $perms = fileperms($file);
        if ($perms === false) {
            throw new Exception();
        }

        $this->currentElements[$file] = array('perms' => $perms,
                                              'mtime' => null,
                                              'isDirectory' => is_dir($file)
                                              );

        if (!$this->currentElements[$file]['isDirectory']) {
            $mtime = filemtime($file);
            if ($mtime === false) {
                throw new Exception();
            }

            $this->currentElements[$file]['mtime'] = $mtime;
        }

        if ($this->isFirstTime) {
            return;
        }

        $perms = fileperms($file);
        if ($perms === false) {
            throw new Exception();
        }

        if (!array_key_exists($file, $this->previousElements)) {
            $this->addEvent(ResourceChangeEvent::EVENT_CREATED, $file);
            return;
        }

        $isDirectory = is_dir($file);
        if ($this->currentElements[$file]['isDirectory'] != $isDirectory) {
            $this->addEvent(ResourceChangeEvent::EVENT_CHANGED, $file);
            return;
        }

        if ($this->previousElements[$file]['perms'] != $perms) {
            $this->addEvent(ResourceChangeEvent::EVENT_CHANGED, $file);
            return;
        }

        if ($isDirectory) {
            return;
        }

        $mtime = filemtime($file);
        if ($mtime === false) {
            throw new Exception();
        }

        if ($this->previousElements[$file]['mtime'] != $mtime) {
            $this->addEvent(ResourceChangeEvent::EVENT_CHANGED, $file);
            return;
        }
    }

    /**
     * Watches for changes in the target directories and returns immediately when
     * changes are detected.
     */
    protected function waitForChanges()
    {
        try {
            while (true) {
                sleep($this->scanInterval);
                clearstatcache();

                $startTime = time();
                foreach ($this->directories as $directory) {
                    $finder = Finder::create()->in($directory);
                    foreach ($finder->getIterator() as $file) {
                        $this->detectChanges($file->getPathname());
                    }
                }
                $endTime = time();
                $elapsedTime = $endTime - $startTime;
                if ($elapsedTime > self::SCAN_INTERVAL_MIN) {
                    $this->scanInterval = $elapsedTime;
                }

                if (!$this->isFirstTime) {
                    reset($this->previousElements);
                    while (list($file, $stat) = each($this->previousElements)) {
                        if (!array_key_exists($file, $this->currentElements)) {
                            $this->addEvent(ResourceChangeEvent::EVENT_REMOVED, $file);
                        }
                    }
                }

                $this->previousElements = $this->currentElements;
                $this->currentElements = array();
                $this->isFirstTime = false;

                if (count($this->eventQueue)) {
                    throw new AlterationException();
                }
            }
        } catch (AlterationException $e) {
        }
    }

    /**
     * @param integer $event
     * @param string  $file
     */
    protected function addEvent($event, $file)
    {
        $this->eventQueue[] = new ResourceChangeEvent($event, $file);
    }

    /**
     */
    protected function invokeCallback()
    {
        call_user_func($this->callback, $this->eventQueue);
        $this->eventQueue = array();
    }
}

/*
 * Local Variables:
 * mode: php
 * coding: iso-8859-1
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * indent-tabs-mode: nil
 * End:
 */
