<?php
/**
 * Return autocomplete strings for advanced search.
 */
class helpdeskBackendSearchAutocompleteController extends waController
{
    public function execute()
    {
        $name = waRequest::request('n');
        $value = waRequest::request('term');
        $limit = waRequest::request('limit', 10, 'int');

        if (!$value || !$name || $limit <= 0) {
            echo '[]';
            return;
        }

        $sql = array();
        $m = new waModel();
        switch($name)
        {
            case '!id':
                $sql[] = "SELECT id AS value
                          FROM helpdesk_request
                          WHERE id LIKE 'l:value%'
                          ORDER BY id
                          LIMIT %LIMIT%";
                break;
            case '!client_name':
                $conditions = array();
                foreach(preg_split('~\s+~', $value) as $t) {
                    if (strlen($t)) {
                        $t = $m->escape($t, 'like');
                        $conditions[] = "(c.firstname LIKE '{$t}%' OR c.middlename LIKE '{$t}%' OR c.lastname LIKE '{$t}%' OR c.name LIKE '{$t}%')";
                    }
                }
                if ($conditions) {
                    $sql[] = "SELECT DISTINCT c.name AS value, c.firstname, c.middlename, c.lastname
                              FROM wa_contact AS c
                              WHERE ".join(' AND ', $conditions)."
                              ORDER BY c.name
                              LIMIT %LIMIT%";
                }
                break;
            case '!client_email':
                $sql[] = "SELECT DISTINCT ce.email AS value
                          FROM wa_contact_emails AS ce
                          WHERE ce.email LIKE '%l:value%'
                          ORDER BY ce.email
                          LIMIT %LIMIT%";
                break;
            default:
//                $sql[] = "SELECT DISTINCT value
//                          FROM helpdesk_request_params
//                          WHERE name=:name
//                              AND value LIKE 'l:value%'
//                          ORDER BY value
//                          LIMIT %LIMIT%";
                
                $sql[] = "SELECT DISTINCT value
                          FROM helpdesk_request_data
                          WHERE field=:name
                              AND value LIKE '%l:value%'
                          ORDER BY value
                          LIMIT %LIMIT%";
                
                break;
        }

        $result = array();
        while ($sql && ( ( $l = $limit - count($result)))) {
            $one_sql = str_replace('%LIMIT%', $l, array_shift($sql));
            $result += $m->query($one_sql, array(
                'name' => $name,
                'value' => $value
            ))->fetchAll('value');
        }
        
        if ($name === '!client_name') {
            foreach ($result as &$r) {
                $r['label'] = waContactNameField::formatName($r);
            }
            unset($r);
        }
        
        $term_safe = htmlspecialchars($value);
        foreach ($result as &$r) {
            $r['label'] = $this->prepare(!empty($r['label']) ? $r['label'] : $r['value'], $term_safe);
        }
        unset($r);
        
        
        echo json_encode(array_values($result));
    }
    
    protected function prepare($str, $term_safe)
    {
        $str = htmlspecialchars($str);
        $reg = array();
        foreach (preg_split("/\s+/", $term_safe) as $t) {
            $t = trim($t);
            if ($t) {
                $reg[] = preg_quote($t, '~');
            }
        }
        if ($reg) {
            $reg = implode('|', $reg);
            $str = preg_replace('~('.$reg.')~ui', '<span class="bold h-highlighted">\1</span>', $str);
        }
        return $str;
    }
    
}

