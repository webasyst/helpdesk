<?php
/**
 * Base class for all JSON controllers.
 * Responsible for common events.
 */
class helpdeskJsonController extends waJsonController
{
    public function run($params = null)
    {
        $this->execute();

        // This is a powerful, but kinda experimental plugin hook. Currently not included in official docs.
        // Plugins can alter output and/or add custom data to the result of any JSON controller.
        $params = array(
            'controller' => $this,
            'response' => &$this->response,
            'errors' => &$this->errors,
        );

        // Do not allow plugins to break our JSON with warnings in non-debug mode
        if (!waSystemConfig::isDebug() && function_exists('ini_set')) {
            ini_set('display_errors', false);
        }

        wa('helpdesk')->event('json_controller', $params);

        $this->display();
    }
}

