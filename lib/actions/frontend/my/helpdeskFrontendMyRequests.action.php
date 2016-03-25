<?php
/**
 * List of requests in personal account.
 * Controller for my.requestlist.html in themes.
 */
class helpdeskFrontendMyRequestsAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        // List of requests
        $c = helpdeskRequestsCollection::create(array(
            array(
                'name' => 'client',
                'params' => array(wa()->getUser()->getId()),
            ),
        ));
        $c->orderBy('created', 'DESC');
        $requests = helpdeskRequest::prepareRequests($c->limit(0)->getRequests());
        $link_tpl = wa()->getRouteUrl('helpdesk/frontend/myRequest', array('id' => '%id%'));
        foreach($requests as $id => &$r) {
            $r['link'] = str_replace('%id%', $r['id'], $link_tpl);
            try {
                $s = helpdeskWorkflow::get($r['workflow_id'])->getStateById($r['state_id']);
                $r['state'] = $s->getOption('customer_portal_name');
                if (!$r['state']) {
                    // Requests in this state are disabled for clients to see
                    unset($requests[$id]);
                    continue;
                }
                if (empty($r['state'])) {
                    $r['state'] = $s->getName();
                }
            } catch (Exception $e) {
                // No workflow or no state anymore for some reason.
                // Ignore such requests.
                unset($requests[$id]);
            }
        }
        unset($r);

        // Forms available for client to submit new requests

        $sm = new helpdeskSourceModel();
        $spm = new helpdeskSourceParamsModel();
        $forms = $this->getForms($sm, $spm, wa()->getRouting()->getDomain());
        $this->view->assign('requests', $requests);
        $this->view->assign('requests_count', count($requests));
        $this->view->assign('action', $this);
        $this->view->assign('forms', $forms);

        $this->view->assign('my_nav_selected', 'requests');

        $this->setThemeTemplate('my.requestlist.html');
        $this->getResponse()->setTitle(_w('Requests'));
        parent::execute();
    }

    public function getFaqCategories()
    {
        return array();
    }

    public function getBreadcrumbs()
    {
        $result = parent::getBreadcrumbs();
        $result[] = array(
            'name' => _w('My account'),
            'url' => wa()->getRouteUrl('helpdesk/frontend/myRequests'),
        );
        return $result;
    }

    public function getForms(helpdeskSourceModel $sm, helpdeskSourceParamsModel $spm, $domain)
    {
        $forms = array();
        $source_params = $spm->getByField(array(
            'name' => 'domain_' . $domain,
            'value' => 1
        ), true);

        foreach ($source_params as $param) {
            $source = $sm->getById($param['source_id']);

            try {
                $s = helpdeskSource::get($source);
                $st = $s->getSourceType();
            } catch (Exception $e) {
                // Something is wrong, e.g. source type does not exist. Ignore this source.
                continue;
            }
            // We're only interested in specific kind of forms
            if ($s->status <= 0 || !$st instanceof helpdeskFormSTInterface) {
                continue;
            }
            $forms[$param['source_id']] = $s;
        }

        $source_params = $spm->select('*')->where("name = :0", array(
            'sort_domain_' . $domain
        ))->order('CAST(value AS UNSIGNED)')->fetchAll();
        $res = array();
        foreach ($source_params as $param) {
            if (isset($forms[$param['source_id']])) {
                $res[] = $forms[$param['source_id']];
                unset($forms[$param['source_id']]);
            }
        }
        $res += $forms;

        return $res;
    }
}

