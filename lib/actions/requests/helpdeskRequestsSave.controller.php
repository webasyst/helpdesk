<?php
/**
 * Receives POST data from forms that create new requests in backend.
 */
class helpdeskRequestsSaveController extends helpdeskJsonController
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

            waSystem::pushActivePlugin($st->getPlugin(), 'helpdesk');
            $this->response = $st->frontendSubmit($this->source);
            waSystem::popActivePlugin();
        } catch(Exception $e) {
            $this->errors[] = $e->getMessage();
        }
    }
}

