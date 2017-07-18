<?php

namespace App;

use App\Log;
use Exception;

/**
* Utilities
*/
class Cmd
{
    

    public static function doCmd($cmd, $cwd=null, $allow_exception=false) {
        try {
            $old_cwd = null;
            if ($cwd !== null) {
                $old_cwd = getcwd();
                chdir($cwd);
            }

            $return = array();
            Log::wlog(($cwd ? '['.$cwd.' #]' : '#').' '.$cmd);
            exec($cmd, $return, $return_code);
            $output = join("\n",$return);
            if (strlen($output)) { Log::wlog($output); }

            if ($old_cwd !== null AND strlen($old_cwd)) { chdir($old_cwd); }

            if ($return_code) { throw new Exception("Command failed with code $return_code".(strlen(trim($output)) > 0 ? "\n".$output : ''), $return_code); }

            return $output;
        } catch (Exception $e) {
            if ($allow_exception) {
                Log::wlog("Error: ".$e->getMessage());
                return null;
            }
            throw $e;
        }
    }


}
