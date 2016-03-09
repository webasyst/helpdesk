<?php
/**
 * Autocomplete for contact search in backend request creation form.
 * See lib/sources/templates/backend.html
 */
class helpdeskBackendContactAutocompleteController extends waController
{
    public function execute()
    {
        $term = waRequest::request('term', null, 'string');
        if (strlen($term) < 3) {
            echo '[]';
            exit;
        }

        $is_user_first = waRequest::request('is_user_first', null, waRequest::TYPE_INT);

        $m = new waModel();

        // The plan is: try queries one by one (starting with fast ones),
        // until we find 5 rows total.
        $sqls = array();

        // Name starts with requested string
        $sqls[] = "SELECT c.id, c.firstname, c.middlename, c.lastname, e.email, d.value AS phone, c.is_user
                   FROM wa_contact AS c
                       LEFT JOIN wa_contact_emails AS e
                           ON e.contact_id=c.id AND e.sort=0
                       LEFT JOIN wa_contact_data AS d
                           ON d.contact_id=c.id AND d.field='phone' AND d.sort=0
                   WHERE (c.firstname LIKE '".$m->escape($term, 'like')."%'
                            OR
                            c.middlename LIKE '".$m->escape($term, 'like')."%'
                                OR
                            c.lastname  LIKE '".$m->escape($term, 'like')."%')"
                                . ( $is_user_first ? 'ORDER BY is_user DESC' : '') . "
                   LIMIT {LIMIT}";

        // Email starts with requested string
        $sqls[] = "SELECT c.id, c.firstname, c.middlename, c.lastname, e.email, d.value AS phone, c.is_user
                   FROM wa_contact AS c
                       JOIN wa_contact_emails AS e
                           ON e.contact_id=c.id
                       LEFT JOIN wa_contact_data AS d
                           ON d.contact_id=c.id AND d.field='phone' AND d.sort=0
                   WHERE e.email LIKE '".$m->escape($term, 'like')."%'"
                                . ( $is_user_first ? 'ORDER BY is_user DESC' : '') . "
                   LIMIT {LIMIT}";

        // Phone contains requested string
        if (preg_match('~^[wp0-9\-\+\#\*\(\)\. ]+$~', $term)) {
            $sqls[] = "SELECT c.id, c.firstname, c.middlename, c.lastname, e.email, d.value as phone, c.is_user
                       FROM wa_contact AS c
                           JOIN wa_contact_data AS d
                               ON d.contact_id=c.id AND d.field='phone'
                           LEFT JOIN wa_contact_emails AS e
                               ON e.contact_id=c.id AND e.sort=0
                       WHERE d.value LIKE '%".$m->escape($term, 'like')."%'"
                                        . ( $is_user_first ? 'ORDER BY is_user DESC' : '') . "
                       LIMIT {LIMIT}";
        }

        // Name contains requested string
        $conditions = array();
        foreach(preg_split('~\s+~', $term) as $t) {
            if (strlen($t)) {
                $conditions[] = "c.name LIKE '%".$m->escape($t, 'like')."%'";
            }
        }
        if ($conditions) {
            $sqls[] = "SELECT c.id, c.firstname, c.middlename, c.lastname, e.email, d.value AS phone, c.is_user
                       FROM wa_contact AS c
                           LEFT JOIN wa_contact_emails AS e
                               ON e.contact_id=c.id AND e.sort=0
                           LEFT JOIN wa_contact_data AS d
                               ON d.contact_id=c.id AND d.field='phone' AND d.sort=0
                       WHERE ".join(' AND ', $conditions)
                                        . ( $is_user_first ? 'ORDER BY is_user DESC' : '') . "
                       LIMIT {LIMIT}";
        }

        // Email contains requested string
        $sqls[] = "SELECT c.id, c.firstname, c.middlename, c.lastname, e.email, d.value AS phone, c.is_user
                   FROM wa_contact AS c
                       JOIN wa_contact_emails AS e
                           ON e.contact_id=c.id
                       LEFT JOIN wa_contact_data AS d
                           ON d.contact_id=c.id AND d.field='phone' AND d.sort=0
                   WHERE e.email LIKE '_%".$m->escape($term, 'like')."%'"
                                    . ( $is_user_first ? 'ORDER BY is_user DESC' : '') . "
                    LIMIT {LIMIT}";

        $result = array();
        $term_safe = htmlspecialchars($term);
        foreach($sqls as $sql) {
            $limit = 5 - count($result);
            if ($limit <= 0) {
                break;
            }
            foreach($m->query(str_replace('{LIMIT}', $limit, $sql)) as $c) {
                $c['name'] = waContactNameField::formatName($c);
                $name = $this->prepare($c['name'], $term_safe);
                $email = $this->prepare(ifset($c['email'], ''), $term_safe);
                $phone = $this->prepare(ifset($c['phone'], ''), $term_safe);
                $phone && $phone = '<i class="icon16 phone"></i>'.$phone;
                $email && $email = '<i class="icon16 email"></i>'.$email;
                !empty($c['is_user']) && $name = '<i class="icon16 user" title="'._ws('User').'"></i>'.$name;

                $result[$c['id']] = array(
                    'id' => $c['id'],
                    'value' => $c['id'],
                    'name' => $c['name'],
                    'is_user' => ifempty($c['is_user']),
                    'label' => implode(', ', array_filter(array($name, $email, $phone))),
                );
            }
        }

        echo json_encode(array_values($result));
        exit;
    }

    protected function prepare($str, $term_safe)
    {
        return preg_replace('~('.preg_quote($term_safe, '~').')~ui', '<span class="bold h-highlighted">\1</span>', htmlspecialchars($str));
    }
}

