<?php
/**
 *
 * This file is licensed under the MIT License. See the LICENSE file.
 *
 * @author Dmitry Volynkin <thesaturn@thesaturn.me>
 */

namespace thesaturn\C14BackupTool;

/**
 * Every project should include class/trait with a lot of different staff
 * Class HelperTrait
 * @package TheSaturn\C14BackupTool
 */
trait HelperTrait
{

    /**
     * @var \Psr\Log\LoggerInterface
     */
    public $logger;

    /**
     * @param $obj any
     * @return string
     */
    public static function objToStr($obj)
    {
        return (empty($obj) === false ? "\n" . print_r($obj, true) : '');
    }
}