<?php
/**
 * Antispam confirmation
 */
class helpdeskFrontendConfirmAction extends helpdeskFrontendViewAction
{
    /** @var helpdeskSource */
    public $source;

    public function execute()
    {
        try {
            $source_id = waRequest::request('source_id');
            $this->source = new helpdeskSource($source_id);
            $st = $this->source->getSourceType();
            if (!($st instanceof helpdeskFrontendSTInterface)) {
                throw new waException('Source is not of expected type.', 404);
            }
            $st->frontendSubmit($this->source);

        } catch(Exception $e) {
            $this->setThemeTemplate('error.html');
            $this->view->assign('error_code', 404);
            $this->view->assign('error_message', $e->getMessage());
            parent::execute();
        }
    }
}

