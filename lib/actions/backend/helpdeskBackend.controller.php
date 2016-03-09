<?php

/**
 * Default controller to show basic layout
 */
class helpdeskBackendController extends waViewController
{
    public function execute() {
        $this->setLayout(new helpdeskBackendLayout());
    }
}
