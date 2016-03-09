<?php
/**
 * Log file with errors of given source
 */
class helpdeskSourcesLogController extends waController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $source_id = waRequest::request('id', 0, 'int');

        $file = tempnam(wa()->getTempPath(), 's'.$source_id.'-');
        if (! ( $fd = fopen($file, 'w'))) {
            throw new waException('Unable to write to file: '.$file);
        }

        $em = new helpdeskErrorModel();
        $rows = $em->query("SELECT * FROM helpdesk_error ".($source_id ? 'WHERE source_id=? ' : '').' ORDER BY id DESC LIMIT 500', $source_id);

        fwrite($fd, "source_id\tdatetime\tmessage\n");
        foreach($rows as $r) {
            fwrite($fd, $r['source_id']);
            fwrite($fd, "\t");
            fwrite($fd, $r['datetime']);
            fwrite($fd, "\t");
            fwrite($fd, str_replace(array("\r", "\n"), ' ', $r['message']));
            fwrite($fd, "\n");
        }
        fclose($fd);

        waFiles::readFile($file, ($source_id ? 'source-'.$source_id : 'sources').'-errors.txt', false);
        @waFiles::delete($file);
        exit;
    }
}

