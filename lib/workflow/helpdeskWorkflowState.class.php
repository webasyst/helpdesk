<?php
/**
 * Base class for all workflow states.
 *
 * Although in theory it is possible to extend this with custom code,
 * it should not be needed: states contain no logic,
 * and any custom preferences can be stored using base class anyway.
 */
class helpdeskWorkflowState extends waWorkflowState
{
    /**
     * List of actions that can be performed from given state.
     * Short for $this->getWorkflow()->getActions($this, $params)
     * See helpdeskWorkflow->getActions()
     */
    public function getActions($params = null)
    {
        return $this->workflow->getActions($this, $params);
    }

    public function getName()
    {
        if ( ( $name = $this->getOption('name'))) {
            return waLocale::fromArray($name);
        }
        return parent::getName();
    }

    public function getDefaultOptions()
    {
        return array_merge(parent::getDefaultOptions(), array(
            // When request is transfered into closed state, helpdesk_request.closed field is set to current datetime
            'closed_state' => false,

            // style attribute for requests in this state
            'list_row_css' => '',

            // customer-visible name of this state
            'customer_portal_name' => '',
        ));
    }

    public function isClosed()
    {
        return !!$this->getOption('closed_state');
    }
}

