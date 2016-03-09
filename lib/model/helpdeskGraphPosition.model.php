<?php
/**
 * Stores coordinates of elements in workflow graph.
 */
class helpdeskGraphPositionModel
{
    public static $positions = null;

    public static function saveSourcesOrder($wf, $source_ids)
    {
        self::ensureSelfPositions();
        self::$positions[$wf]['sources'] = array_fill_keys($source_ids, array('x' => 0, 'y' => 0));
        self::saveConfig();
    }

    public static function sortSources($wf, $sources)
    {
        self::ensureSelfPositions();
        if (!isset(self::$positions[$wf]) || empty(self::$positions[$wf]['sources']) || !is_array(self::$positions[$wf]['sources'])) {
            return $sources;
        }

        $result = array_intersect_key(self::$positions[$wf]['sources'], $sources);
        foreach($sources as $k => $v) {
            $result[$k] = $v;
        }

        return $result;
    }

    public static function getPositions($wf = null)
    {
        self::ensureSelfPositions();
        if (!$wf) {
            return self::$positions;
        }
        if (isset(self::$positions[$wf])) {
            return self::$positions[$wf];
        }
        return array();
    }

    public static function savePosition($wf, $type, $id, $x, $y)
    {
        self::ensureSelfPositions();
        if (!isset(self::$positions[$wf])) {
            self::$positions[$wf] = array();
        }
        if (!isset(self::$positions[$wf][$type])) {
            self::$positions[$wf][$type] = array();
        }
        self::$positions[$wf][$type][$id] = array(
            'x' => $x,
            'y' => $y,
        );
        self::saveConfig();
    }

    protected static function saveConfig()
    {
        // TODO: Use system function
        $file = wa()->getConfig()->getConfigPath('graph.php', true, 'helpdesk');
        if (!file_exists($file)) {
            $fd = fopen($file, 'w');
            if (!flock($fd, LOCK_EX)) {
                throw new waException('Unable to lock '.$file);
            }
        } else {
            $fd = fopen($file, 'a+');
            if (!flock($fd, LOCK_EX)) {
                throw new waException('Unable to lock '.$file);
            }
        }
        ftruncate($fd, 0);
        fseek($fd, 0);
        fwrite($fd, "<?php\nreturn ".var_export(self::$positions, TRUE).";\n");
        flock($fd, LOCK_UN);
        fclose($fd);
    }

    protected static function ensureSelfPositions()
    {
        if (self::$positions !== null) {
            if (!is_array(self::$positions)) {
                self::$positions = array();
            }
            return;
        }

        $file = wa()->getConfig()->getConfigPath('graph.php', true, 'helpdesk');
        if (file_exists($file)) {
            self::$positions = include($file);
        } else {
            self::$positions = array();
        }
    }
}

