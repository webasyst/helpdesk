<?php

interface helpdeskWorkflowActionAutoInterface
{
    /**
     * @param $timeout array('day' => int, 'hour' => int, 'minute' => int)
     * @return array
     */
    public function getTimeout();

    /**
     * @param $timeout array('day' => int, 'hour' => int, 'minute' => int)
     * @return helpdeskWorkflowActionAutoInterface $this
     */
    public function setTimeout($timeout);

    /**
     * @return string
     */
    public function getActorName();

    /**
     * @return mixed
     */
    public static function getDefaultActorName();

    /**
     * @return mixed
     */
    public function getCreatedDatetime();
}