<?php

/**
 * Default controller to show basic layout
 */
class helpdeskBackendController extends waViewController
{
    public function execute() {
        if (waRequest::isMobile()) {
            $this->setLayout(new helpdeskMobileLayout());
        } else {
            $this->setLayout(new helpdeskBackendLayout());
        }
    }
}
