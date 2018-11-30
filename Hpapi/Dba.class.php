<?php

namespace Hpapi;

class Dba {

    public  $userId;
    public  $model;                                 // Database definition
    public  $modelDb;                               // Model database name
    public  $modelName;                             // Model name
    public  $columns;                               // Model definitions

    public function __construct (\Hpapi\Hpapi $hpapi) {
        $this->hpapi                                = $hpapi;
        $this->userId                               = $this->hpapi->userId;
    }

    public function __destruct ( ) {
    }

// MODEL DEFINITION

    private function columnsCall () {
        try {
            $cs                                     = $this->hpapi->dbCall (
                'hpapiDbaColumns'
               ,$this->modelName
               ,$this->modelDb
            );
        }
        catch (\Exception $e) {
            $this->hpapi->diagnostic ($e->getMessage());
            $this->hpapi->object->response->error   = HPAPI_STR_ERROR_DB;
            return false;
        }
        $columns                                    = array ();
        foreach ($cs as $c) {
            if ($c['inserters']) {
                $c['inserters']                     = explode ('::',$c['inserters']);
            }
            else {
                $c['inserters']                     = array ();
            }
            if ($c['selectors']) {
                $c['selectors']                     = explode ('::',$c['selectors']);
            }
            else {
                $c['selectors']                     = array ();
            }
            if ($c['updaters']) {
                $c['updaters']                      = explode ('::',$c['updaters']);
            }
            else {
                $c['updaters']                      = array ();
            }
            if ($c['relations']) {
                $c['relations']                     = explode ('::',$c['relations']);
            }
            else {
                $c['relations']                     = array ();
            }
            array_push ($columns,$c);
        }
        return $columns;
    }

    private function columnsLoad ( ) {
        if (HPAPI_DBA_COLS_DYNAMIC) {
            return $this->columnsCall ();
        }
        else {
            $file                                   = HPAPI_DBA_COLS_DIR.'/'.$model.HPAPI_DBA_COLS_FILE_SUFFIX;
            if (is_readable($file)) {
                $privileges                         = require $file;
            }
            if (!is_array($privileges)) {
                $privileges                         = $this->columnsCall ();
                try {
                    $this->hpapi->exportArray ($file,$privileges);
                }
                catch (\Exception $e) {
                    $this->hpapi->diagnostic ($e->getMessage());
                    $this->hpapi->object->response->error = HPAPI_DBA_STR_PRIV_WRITE;
                    return false;
                }
            }
        }
        return $privileges;
    }

    private function columnsReset ($model) {
        if (!is_writable(HPAPI_DBA_COLS_DIR)) {
            throw new \Exception (HPAPI_DBA_STR_COLS_DIR);
            return false;
        }
        try {
            $file                               = HPAPI_DBA_COLS_DIR.'/'.$model.HPAPI_DBA_COLS_FILE_SUFFIX;
            $fp                                 = fopen ($file,'w');
            fwrite ($fp,"<?php\nreturn false;\n");
            fclose ($fp);
            chmod ($file,0666);
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage);
            return false;
        }
    }

    private function modelDbName ($dsn) {
        $parts                                      = explode (';',$dsn);
        foreach ($parts as $part) {
            $kv                                     = explode ('=',$part);
            if ($kv[0]=='dbname') {
                return $kv[1];
            }
        }
    }

    private function modelLoad ($model) {
        if (!property_exists($this->hpapi->models,$model)) {
            $this->hpapi->object->response->error   = HPAPI_DBA_STR_MODEL;
            return false;
        }
        $this->model                                = $this->hpapi->models->{$model};
        $this->modelDb                              = $this->modelDbName ($this->model->dsn);
        $this->modelName                            = $model;
        $this->columns                              = $this->columnsLoad ();
        if (!$this->columns) {
            return false;
        }
        try {
            $this->db                               = new \Hpapi\DbaDb ($this->hpapi,$this->model);
            return true;
        }
        catch (\Exception $e) {
            $this->hpapi->object->response->error   = HPAPI_DBA_STR_DB_OBJ;
            $this->hpapi->diagnostic ($e->getMessage());
            return false;
        }
    }

    private function modelTable ($tName) {
        $table                                      = null;
        $relations                                  = array ();
        foreach ($this->columns as $column) {
            if ($column['table']!=$tName) {
                continue;
            }
            if (!$table) {
                $table                              = new \stdClass ();
                $table->model                       = $this->modelName;
                $table->table                       = $tName;
                $table->mayInsert                   = $this->usergroupMatch ($column['inserters']);
                $table->primary                     = array ();
                $table->relations                   = new \stdClass ();
                $table->title                       = $column['title'];
                $table->description                 = $column['description'];
                $table->columns                     = new \stdClass ();
            }
            if ($column['isPrimary']) {
                array_push ($table->primary,$column['column']);
            }
            if ($column['relations']) {
                foreach ($column['relations'] as $r) {
                    $ktc                            = explode ('.',$r);
                    if (!property_exists($table->relations,$ktc[0])) {
                        $table->relations->{$ktc[0]} = new \stdClass ();
                        $table->relations->{$ktc[0]}->table = $ktc[1];
                        $table->relations->{$ktc[0]}->columns = new \stdClass ();
                    }
                    $table->relations->{$ktc[0]}->columns->{$column['column']} = $ktc[2];
                }
            }
            $c                                      = new \stdClass ();
            $c->maySelect                           = $this->usergroupMatch ($column['selectors']);
            $c->mayUpdate                           = $this->usergroupMatch ($column['updaters']);
            $cname                                  = $column['column'];
            unset ($column['table']);
            unset ($column['inserters']);
            unset ($column['relations']);
            unset ($column['title']);
            unset ($column['description']);
            unset ($column['column']);
            unset ($column['selectors']);
            unset ($column['updaters']);
            foreach ($column as $k=>$v) {
                if ($k=='table' || $k=='column') {
                    continue;
                }
                $c->{$k}                            = $v;
            }
            $table->columns->{$cname}               = $c;
        }
        return $table;
    }

// UTILITIES

    private function inputValidate ($object) {
        if (!is_object($object)) {
            $this->hpapi->object->response->error   = HPAPI_DBA_STR_IN_OBJECT;
            return false;
        }
        if (!property_exists($object,'table')) {
            $this->hpapi->object->response->error   = HPAPI_DBA_STR_IN_TABLE;
            return false;
        }
        return true;
    }

    private function usergroupMatch ($usergroups) {
        foreach ($this->hpapi->usergroups as $g) {
            if (in_array($g['usergroup'],$usergroups)) {
                return true;
            }
        }
        return false;
    }

// DATA MANIPULATION

    public function rowInsert ($object) {
        if (!$this->inputValidate($object)) {
            return false;
        }
        if (!property_exists($object,'row') || !is_object($object->row)) {
            $this->hpapi->object->response->error       = HPAPI_DBA_STR_IN_ROW;
            return false;
        }
        if (!$this->modelLoad($object->model)) {
            return false;
        }
        $table = $this->modelTable ($object->table);
        $count = 0;
        foreach ($object->row as $column=>$value) {
            if (!property_exists($table->columns,$column)) {
                $this->hpapi->object->response->error   = HPAPI_DBA_STR_IN_COL_EXIST.' "'.$column.'"';
                return false;
            }
            if (!$table->columns->{$column}->mayUpdate) {
                $this->hpapi->object->response->error   = HPAPI_DBA_STR_IN_COL_PRIV_UPDATE.' "'.$column.'"';
                return false;
            }
            if ($table->columns->{$column}->isAutoIncrement) {
                $this->hpapi->object->response->error   = HPAPI_DBA_STR_IN_COL_AUTO_INC.' "'.$column.'"';
                return false;
            }
            try {
                $count++;
                $this->hpapi->validation ($table->columns->{$column}->heading,$count,$table->columns->{$column},$value);
            }
            catch (\Exception $e) {
                $this->hpapi->object->response->error   = HPAPI_DBA_STR_IN_COL_VALID.': '.$e->getMessage();
            }
            $this->db->addColumn ($column,$value);
        }
        $this->db->setQueryType ('insert');
        $this->db->setTable ($object->table);
        $this->db->queryBuild ();
        $this->db->queryExecute ();
    }

    public function rowUpdate ($object) {
        if (!$this->modelLoad($object->model)) {
            return false;
        }
    }

    public function rowsSelect ($object) {
        if (!$this->modelLoad($object->model)) {
            return false;
        }
    }

    public function tupleUpdate ($object) {
        if (!$this->modelLoad($object->model)) {
            return false;
        }
    }

// MANIPULATING PERMISSIONS

    public function grantAllowed ($type,$usergroup='') {
        try {
            $this->hpapi->dbCall (
                'hpapiDbaGrantAllowed'
               ,$this->userUUID
               ,$type
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        foreach ($grant as $grt) {
            if (preg_match($gtr['expression'],$match)) {
                return true;
            }
        }
        return false;
    }

    // MANIPULATING USER GROUP MEMBERSHIP

    public function grantUsergroupToUser ($usergroup,$user_uuid) {
        if (!$this->grantAllowed('membership',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaMembershipInsert'
               ,$usergroup
               ,$user_uuid
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function revokeUsergroupFromUser ($usergroup,$user_uuid) {
        if (!$this->grantAllowed('membership',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaMembershipDelete'
               ,$usergroup
               ,$user_uuid
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function showGrantsForUser ($user_uuid) {
        try {
            $usergroups = $this->hpapi->dbCall (
                'hpapiDbaMembershipsForUser'
               ,$user_uuid
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $usergroups;
    }

    public function showGrantedUsers ($usergroup='*') {
        try {
            $users      = $this->hpapi->dbCall (
                'hpapiDbaMembershipsForUsergroup'
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $users;
    }

    // MANIPULATE METHOD ACCESS

    public function grantMethodToUsergroup ($vendor,$package,$class,$method,$usergroup) {
        if (!$this->grantAllowed('run',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaRunInsert'
               ,$vendor
               ,$package
               ,$class
               ,$method
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function revokeMethodFromUsergroup ($vendor,$package,$class,$method,$usergroup) {
        if (!$this->grantAllowed('run',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaRunDelete'
               ,$vendor
               ,$package
               ,$class
               ,$method
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function showGrantsForUsergroup ($usergroup='*') {
        try {
            $methods    = $this->hpapi->dbCall (
                'hpapiDbaRunsForUsergroup'
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $methods;
    }

    public function showGrantedUsergroups ($vendor='*',$package='*',$class='*',$method='*') {
        try {
            $usergroups = $this->hpapi->dbCall (
                'hpapiDbaRunsForMethod'
               ,$vendor
               ,$package
               ,$class
               ,$method
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $usergroups;
    }

    // MANIPULATE STORED PROCEDURE ACCESS

    public function grantSprToMethod ($model,$spr,$vendor,$package,$class,$method) {
        if (!$this->grantAllowed('call')) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaCallInsert'
               ,$model
               ,$spr
               ,$vendor
               ,$package
               ,$class
               ,$method
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function revokeSprFromMethod ($model,$spr,$vendor,$package,$class,$method) {
        if (!$this->grantAllowed('call')) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaCallDelete'
               ,$model
               ,$spr
               ,$vendor
               ,$package
               ,$class
               ,$method
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function showGrantsForSpr ($model,$spr='*') {
        try {
            $methods    = $this->hpapi->dbCall (
                'hpapiDbaCallsForSpr'
               ,$model
               ,$spr
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $methods;
    }

    public function showGrantedSprs ($vendor='*',$package='*',$class='*',$method='*') {
        try {
            $sprs       = $this->hpapi->dbCall (
                'hpapiDbaCallsForMethod'
               ,$vendor
               ,$package
               ,$class
               ,$method
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $sprs;
    }

    // MANIPULATE INSERT PERMISSIONS

    public function grantInsertToUsergroup ($model,$table,$usergroup) {
        if (!$this->grantAllowed('insert',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaInsertInsert'
               ,$model
               ,$table
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function revokeInsertFromUsergroup ($model,$table,$usergroup) {
        if (!$this->grantAllowed('insert',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaInsertDelete'
               ,$model
               ,$table
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function showInsertsForUsergroup ($model,$table='*') {
        try {
            $inserts    = $this->hpapi->dbCall (
                'hpapiDbaInsertsForUsergroup'
               ,$model
               ,$table
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $inserts;
    }


    // MANIPULATE SELECT PERMISSIONS

    public function grantSelectToUsergroup ($model,$table,$column,$usergroup) {
        if (!$this->grantAllowed('select',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaSelectInsert'
               ,$model
               ,$table
               ,$column
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function revokeSelectFromUsergroup ($model,$table,$column,$usergroup) {
        if (!$this->grantAllowed('select',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaSelectDelete'
               ,$model
               ,$table
               ,$column
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function showSelectsForUsergroup ($model,$table='*',$column='*') {
        try {
            $selects    = $this->hpapi->dbCall (
                'hpapiDbaSelectsForUsergroup'
               ,$model
               ,$table
               ,$column
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $selects;
    }

    // MANIPULATE UPDATE PERMISSIONS

    public function grantUpdateToUsergroup ($model,$table,$column,$usergroup) {
        if (!$this->grantAllowed('update',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaUpdateInsert'
               ,$model
               ,$table
               ,$column
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function revokeUpdateFromUsergroup ($model,$table,$column,$usergroup) {
        if (!$this->grantAllowed('update',$usergroup)) {
            throw new \Exception (HPAPI_DBA_STR_GRANT_ALLOW);
            return false;
        }
        try {
            $this->hpapi->dbCall (
                'hpapiDbaUpdateDelete'
               ,$model
               ,$table
               ,$column
               ,$usergroup
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return true;
    }

    public function showUpdatesForUsergroup ($model,$table='*',$column='*') {
        try {
            $updates    = $this->hpapi->dbCall (
                'hpapiDbaUpdatesForUsergroup'
               ,$model
               ,$table
               ,$column
            );
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        return $updates;
    }

    // DESCRIBE DATA STRUCTURE

    public function describeTable ($object) {
        if (!$this->inputValidate($object)) {
            return false;
        }
        if (!$this->modelLoad($object->model)) {
            return false;
        }
        return $this->modelTable ($object->table);
   }

    public function describeRelations ($dbName,$tableName,$weak=false) {
        try {
            if ($weak) {
                $columns            = $this->hpapi->dbCall (
                    'hpapiDbaColumnsWeak'
                   ,$dbName
                   ,$tableName
                );
            }
            else {
                $columns            = $this->hpapi->dbCall (
                    'hpapiDbaColumnsStrong'
                   ,$dbName
                   ,$tableName
                );
            }
            $columns                = $this->hpapi->parse2D ($columns);
        }
        catch (\Exception $e) {
            throw new \Exception ($e->getMessage());
            return false;
        }
        $relations = new \stdClass ();
        foreach ($columns as $c) {
            if (!property_exists($relations,$c->table)) {
                $relations->{$c->table}                 = new \stdClass ();
                $relations->{$c->table}->model          = $c->model;
                $relations->{$c->table}->table          = $c->table;
                $relations->{$c->table}->title          = $c->title;
                $relations->{$c->table}->description    = $c->description;
                $relations->{$c->table}->columns        = array ();
            }
            unset ($c->title);
            unset ($c->description);
            array_push ($relations->{$c->table}->columns,$c);
        }
        return $relations;
    }

}

