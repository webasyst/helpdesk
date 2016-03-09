<?php
/**
 * Instances of this class represent workflow source types.
 * Sources are entities that create new requests from user-provided data:
 * email messages, forms, ets.
 *
 * helpdeskSource is a storage container for source settings.
 * helpdeskSourceType, on the other hand, contains actual logic.
 *
 * Each source type is responsible for the following:
 *
 * - Show source settings page in workflow editor,
 *   and accept submit from this form:
 *   See $this->settingsController()
 *
 * - If the source requires code to be executed periodically (e.g. fetch mail from time to time),
 *   source type must implement `helpdeskCronSTInterface`. If contains a single ->cronJob() method
 *   that is called from time to time (depending on whether CRON is set up at all and whether there are
 *   currently backend users online).
 *
 * - If the source represents a web-form, it is possible to show its HTML in one
 *   of several places Helpdesk app is aware of:
 *   - On 'New request' page in backend ('backend' type)
 *   - On 'New request' page in customer portal ('auth_frontend' type)
 *   - Using {$wa->helpdesk->form(id)} tag ('public_frontend' type)
 *   To use one (or all) of above, source type must implement `helpdeskFormSTInterface`
 *   and response correctly depending on $form_type parameter.
 *
 * - If the source type needs some data to be POSTed via frontend (e.g.: a frontend form,
 *   or antispam confirmation link), implement `helpdeskFrontendSTInterface`.
 *
 * For live examples, see existing source types code.
 *
 * When system needs to list all installed source types (e.g. a dropdown menu to create new source),
 * it looks by class name. All source types must be named according to this rule:
 * helpdesk(.+)SourceType, (.+) being type identifier as stored in helpdesk_source.type db field.
 * (Class names are case insensitive in PHP. Type strings are all lowercase by consideration.)
 * See self::getSourceTypes()
 */
abstract class helpdeskSourceType
{
    //
    // Static
    //

    /**
      * Get an object representing given source type.
      *
      * @param string $type source type identifier as stored in helpdesk_source.type db field
      * @return helpdeskSourceType
      * @throws waException if no such source type found
      */
    public static function get($type)
    {
        if (!$type) {
            throw new waException('Attempt to create a source with empty type.', 404);
        }
        $class = 'helpdesk'.ucfirst($type).'SourceType';
        if (class_exists($class)) {
            $refl = new ReflectionClass($class);
            if ($refl->isAbstract()) {
                throw new waException('Class is abstract: ' . $class);
            }
            return new $class();
        }
        throw new waException('Unknown source type: '.$type.' ('.$class.')', 404);
    }

    /** @return array list of all source types as type => helpdeskSourceType instance */
    public static function getSourceTypes($all = true)
    {
        $ignored = array();
        if (!$all) {
            $ignored = array('backend', 'customerportal');
        }
        // Use autoload class to get all classnames
        $result = array();
        foreach (waAutoload::getInstance()->getClasses() as $class => $file) {
            if (!preg_match('~^helpdesk(.+)SourceType$~i', $class, $m)) {
                continue;
            }
            try {
                $st = self::get($m[1]);
                $type = $st->getType();
                if (!in_array(strtolower($type), $ignored)) {
                    $result[$type] = $st;
                }
            } catch (Exception $e) {

            }
        }
        return $result;
    }

    //
    // Non-static
    //

    /** @var string Human-readable name to show in user interface. Override in subclasses. */
    protected $name = null;

    /** @var waSmartyView cache for $this->getView() */
    protected $view = null;

    public function __construct()
    {
        if (!$this->name) {
            $this->name = ucfirst($this->getType());
        }

        // Call init() in plugin's locale
        $plugin = $this->getPlugin();
        $plugin && waSystem::pushActivePlugin($plugin, 'helpdesk');
        $plugin && helpdeskHelper::loadPluginLocale($plugin);
        $this->init();
        $plugin && waSystem::popActivePlugin();
    }

    /** Function called by a constructor after all class fields are initialized. */
    protected function init()
    {
        // to be overriden in subclasses
    }

    /**
     * Interface function for workflow editor.
     * Returns HTML form for given source's settings.
     *
     * If data came via POST, saves them to database.
     * In this case may return '' to close the editor and return to workflow page.
     *
     * @param helpdeskWorkflow $wf existing workflow this editor is caled in context of (may be null in exotic cases when source uses several workflows)
     * @param helpdeskSource $source existing source to edit; omit to show empty (creation) form
     * @return string HTML
     */
    public function settingsController(helpdeskWorkflow $wf = null, helpdeskSource &$source = null)
    {
        return ''; // no customizable settings
    }

    /** @return string this source type's id as stored in helpdesk_source.type field */
    public function getType()
    {
        if (!preg_match('~^helpdesk(.+)SourceType$~', get_class($this), $m)) {
            throw new waException('Bad class name for a source type: '.get_class($this));
        }
        return strtolower($m[1]);
    }

    /** Human-readable name to show in user interface */
    public function getName()
    {
        return $this->name;
    }

    /**
     * New source of this type with default values set up.
     * Wraps $this->buildNewSource() (which should be overriden in subclasses)
     * adding localization support via _wp().
     * @return helpdeskSource
     */
    public function getNewSource()
    {
        $plugin = $this->getPlugin();
        $plugin && waSystem::pushActivePlugin($plugin, 'helpdesk');
        $result = $this->buildNewSource();
        $plugin && waSystem::popActivePlugin();
        return $result;
    }

    //
    // To be overriden in subclasses
    //

    /**
     * Provides information about a source for workflow editor.
     * Must return: array(
     *   workflow_id => array(
     *     'new_states' => array(...), // all state_ids this source can possibly create new requests in
     *   ),
     * )
     */
    abstract public function describeBehaviour($source);

    /**
     * @return helpdeskSource see $this->getNewSource()
     */
    protected function buildNewSource()
    {
        $source = new helpdeskSource();
        $source->type = $this->getType();
        $source->name = $this->getName();
        return $source;
    }

    /**
     * Request params that this action possibly adds to request.
     * Used to build list of fields for advanced search.
     * @return array param_id => options; for simple text fields options is just a string with human readable name.
     */
    public function getRequestParams()
    {
        return array();
    }

    /**
     * Get smarty view for this source type.
     * Also available as $this->view after first call of this function.
     * @return waSmartyView
     */
    protected function getView()
    {
        if (!$this->view) {
            $this->view = waSystem::getInstance()->getView();
            $this->view->assign('source_type', $this);
        }
        return $this->view;
    }

    /** Return html generated by $this->getView() */
    protected function display($tmpl_suffix='')
    {
        $dir = dirname(waAutoload::getInstance()->get(get_class($this))).'/templates/';
        $tmpl_suffix && $tmpl_suffix = '_'.$tmpl_suffix;
        $basename = $this->getType() . '/' . $this->getType() . $tmpl_suffix . $this->getView()->getPostfix();
        return $this->getView()->fetch($dir.$basename);
    }

    /**
     * If this source type is a part of a plugin, then return plugin id.
     * Otherwise, return null.
     * @return string plugin id or null
     */
    public function getPlugin()
    {
        return helpdeskHelper::getPluginByClass(get_class($this));
    }

    /**
     * @return bool
     */
    public static function cronSourceTypesExist()
    {
        try {
            $sm = new helpdeskSourceModel();
            foreach ($sm->getAll(true) as $source_id => $source) {
                if ($source['status'] <= 0) {
                    continue;
                }
                try {
                    $s = helpdeskSource::get($source);
                    $st = $s->getSourceType();
                } catch (Exception $e) {
                    continue;
                }

                if ($st instanceof helpdeskCronSTInterface) {
                    return true;
                }
            }
        } catch (Exception $e) {
            return false;
        }

        return false;
    }
}

interface helpdeskCronSTInterface
{
    /**
      * Function called periodically by a cron (or its emulator) to check messages from each source.
      *
      * Return value is used to clean error flag from the source. If this function returns true,
      * error flag is removed if it was set.
      *
      * @param helpdeskSource $source
      * @throws waException in case of error
      * @return bool
      */
    public function cronJob(helpdeskSource $source);

}

interface helpdeskFrontendSTInterface
{
    public function frontendSubmit(helpdeskSource $source);
}

interface helpdeskFormSTInterface extends helpdeskFrontendSTInterface
{
    public function isFormEnabled(helpdeskSource $source);

    public function getFormHtml(helpdeskSource $source);
}

