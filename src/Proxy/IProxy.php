<?php
/**
 * @desc: proxy handle interface
 * @author: leandre <niulingyun@camera360.com>
 * @date: 2017/4/11
 * @copyright All rights reserved.
 */

namespace PG\MSF\Proxy;

interface IProxy
{
    public function check();

    public function handle($method, $arguments);

    public function startCheck();
}
