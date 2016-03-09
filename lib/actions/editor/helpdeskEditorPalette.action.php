<?php
/**
 * Color palette to use in state and action editors.
 */
class helpdeskEditorPaletteAction extends helpdeskViewAction
{
    public function execute()
    {
        $pre_selected = waRequest::param('pre_selected');
        $uniqid = waRequest::param('uniqid');
        if (empty($uniqid)) {
            $uniqid = uniqid('p');
        }

        $this->view->assign('uniqid', $uniqid);
        $this->view->assign('colors', self::getColors());
        $this->view->assign('basic_colors', self::getBasicColors());
        $this->view->assign('pre_selected', $pre_selected);
    }

    public static function getColors()
    {
        $greys = array();
        for ($r = 0x00; $r <= 0xff; $r += 0x17) {
            $greys[] = sprintf('#%02x%02x%02x', $r, $r, $r);
        }

        $cc = array();
        for ($r = 0x00; $r <= 0xff; $r += 0x33) {
            for ($g = 0x00; $g <= 0xff; $g += 0x33) {
                for ($b = 0x00; $b <= 0xff; $b += 0x33) {
                    $cc[] = sprintf('#%02x%02x%02x', $r, $g, $b);
                }
            }
        }

        // Interleave colors in a pretty way
        $colors = array();
        while($cc) {
            $cc1 = array_splice($cc, 0, 36);
            $cc2 = array_splice($cc, 0, 36);
            while ($cc1) {
                $line1 = array_splice($cc1, 0, 6);
                $line2 = array_splice($cc2, 0, 6);
                $colors = array_merge($colors, $line1, $line2);
            }
        }

        return array_merge($greys, $colors);
    }

    public static function getBasicColors()
    {
        return array(
            '#cc0000',
            '#ed5f21',
            '#fae300',
            '#5b9c0a',
            '#11c9c0',
            '#42a7f5',
            '#0a0d9c',
            '#500a9c',
            '#990a9c',
            '#000000',
        );
    }
}

