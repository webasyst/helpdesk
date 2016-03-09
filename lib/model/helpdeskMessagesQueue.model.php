<?php

class helpdeskMessagesQueueModel extends waModel
{
    protected $table = 'helpdesk_messages_queue';

    public function sendAll()
    {
        $limit = (int) $this->getOption('send_max_count');
        for ($i = 0; $i < $limit; $i += 1)
        {
            $items = $this->select('*')->order('id DESC')->limit(1)->fetchAll();
            if (!$items) {       // no items anymore
                break;
            }
            $item = $items[0];
            $this->deleteById($item['id']);

            if (!$item['data']) {
                continue;
            }

            $params = unserialize($item['data']);
            if (empty($params['address'])) {
                continue;
            }

            // Send message
            try {
                $m = new waMailMessage(
                    htmlspecialchars_decode(ifempty($params['subject'], '')),
                    ifset($params['content'], '')
                );
                $m->setTo($params['address'])->setFrom(ifempty($params['from'], ''));
                if (isset($params['log_id'])) {
                    $log = new helpdeskRequestLog($params['log_id']);
                    foreach($log->attachments as $file) {
                        if (empty($file['file'])) {
                            continue;
                        }
                        $m->addAttachment($file['file'], ifempty($file['name'], ''));
                    }
                }
                $sent = $m->send();
                $reason = 'waMailMessage->send() returned FALSE';
            } catch (Exception $e) {
                $sent = false;
                $reason = $e->getMessage();
            }

            if (!$sent) {
                if (is_array($params['address'])) {
                    $params['address'] = var_export($params['address'], true);
                }
                waLog::log('Unable to send email from '.ifempty($params['from'], '').' to '.$params['address'].
                        ' ('.ifempty($params['subject'], '').'): '.$reason, 'helpdesk.log');
            }

        }
    }

    public function push($data)
    {
        if (!empty($data['address'])) {
            $insert = array(
                'created' => date('Y-m-d H:i:s'),
                'data' => serialize($data)
            );
            $id = $this->insert($insert);
            $this->shrink();
            return $id;
        }
        return false;
    }

    public function clearAll()
    {
        $this->query("DELETE FROM {$this->table} WHERE 1");
    }

    public function shrink()
    {
        $max_size = (int) $this->getOption('max_size');
        $count = $this->countAll();
        if ($count > $max_size) {
            $id = $this->select('id')->order('id DESC')->limit("{$max_size}, 1")->fetchField();
            $this->query("DELETE FROM `{$this->table}` WHERE id <= {$id}");
        }
    }

    private function getOptions()
    {
        $app_id = 'helpdesk';
        $send_max_count = wa($app_id)->getConfig($app_id)->getOption('messages_queue_send_max_count');
        if ($send_max_count === null) {
            $send_max_count = 250;
        }
        $max_size = wa($app_id)->getConfig($app_id)->getOption('messages_queue_max_size');
        if ($max_size === null) {
            $max_size = 100000;
        }
        return array(
            'send_max_count' => $send_max_count,
            'max_size' => $max_size
        );
    }

    private function getOption($name)
    {
        $options = $this->getOptions();
        return isset($options[$name]) ? $options[$name] : null;
    }
}

