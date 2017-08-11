<?

class HomeKitManager
{

    const classPrefix = "HAPAccessory";
    const configurationClassPrefix = "HAPAccessoryConfiguration";
    const propertyPrefix = "Accessory";

    private static $supportedAccessories = Array();

    public static function registerAccessory($accessory) {

        //Check if the same service was already registered
        if(in_array($accessory, self::$supportedAccessories))
            throw new Exception("Cannot register accessory! " . $accessory . " is already registered.");

        //Add to our static array
        self::$supportedAccessories[] = $accessory;

    }

    private $registerProperty = null;
    private $instanceID = 0;

    public function __construct($instanceID, $registerProperty) {

        $this->registerProperty = $registerProperty;
        $this->instanceID = $instanceID;

    }

    public function registerProperties() {

        //This will be incremented after each change
        call_user_func($this->registerProperty, "ConfigurationNumber", 0);

        //Save a hash over all accessory properties to only increment number on real changes
        call_user_func($this->registerProperty, "ConfigurationHash", 0);

        //Add all accessory specific properties
        foreach(self::$supportedAccessories as $accessory) {
            call_user_func($this->registerProperty, self::propertyPrefix . $accessory, "[]");
        }

    }

    public function getAccessories() {

        $aidList = Array();

        $accessories = Array((new HAPAccessoryBridge())->doExport(1));
        foreach(self::$supportedAccessories as $accessory) {
            $datas = json_decode(IPS_GetProperty($this->instanceID, self::propertyPrefix . $accessory), true);
            foreach($datas as $data) {

                if(in_array($data["ID"], $aidList))
                    throw new Exception("AccessoryID has to be unique for all accessories");

                $class = self::classPrefix . $accessory;
                $accessories[] = (new $class($data))->doExport($data["ID"]);

                //Add to id list
                $aidList[] = $data["ID"];

            }
        }

        return $accessories;

    }

    public function updateAccessories() {

        $ids = Array();

        //Check that all IDs have distinct values and build an id array
        foreach(self::$supportedAccessories as $accessory) {
            $datas = json_decode(IPS_GetProperty($this->instanceID, self::propertyPrefix . $accessory), true);
            foreach($datas as $data) {
                if(in_array($data["ID"], $ids))
                    throw new Exception("InstanceID has to be unique for all characteristics");
                $ids[] = $data["ID"];
            }
        }

        //Sort array and determine highest value
        rsort($ids);

        //We have at least AccessoryID 1 used for the Bridge accessory
        $highestID = 1;

        //Highest value is first
        if((sizeof($ids) > 0) && ($ids[0] > 0)) {
            $highestID = $ids[0];
        }

        //Update all properties and ids which are currently zero
        $wasChanged = false;
        foreach(self::$supportedAccessories as $accessory) {
            $wasUpdated = false;
            $datas = json_decode(IPS_GetProperty($this->instanceID, self::propertyPrefix . $accessory), true);
            foreach($datas as &$data) {
                if($data["ID"] == 0) {
                    $data["ID"] = ++$highestID;
                    $wasChanged = true;
                    $wasUpdated = true;
                }
            }
            if($wasUpdated) {
                IPS_SetProperty($this->instanceID, self::propertyPrefix . $accessory, json_encode($datas));
            }
        }

        //if we have no new ids, lets check if anything else has been changed
        if(!$wasChanged) {
            $data  = "";
            //Collect all properties
            foreach(self::$supportedAccessories as $accessory) {
                $data .= IPS_GetProperty($this->instanceID, self::propertyPrefix . $accessory);

            }
            $hash = md5($data);
            if(IPS_GetProperty($this->instanceID, "ConfigurationHash") != $hash) {
                IPS_SetProperty($this->instanceID, "ConfigurationHash", $hash);
                $wasChanged = true;
            }
        }

        //This is dangerous. We need to be sure that we do not end in an endless loop!
        if($wasChanged) {

            //Increment configuration number so the hap device will reload all accessories
            IPS_SetProperty($this->instanceID, "ConfigurationNumber", IPS_GetProperty($this->instanceID, "ConfigurationNumber") + 1);

            //Save. This will start a recursion. We need to be careful, that the recursion stops after this.
            IPS_ApplyChanges($this->instanceID);

        }

    }

    public function getConfigurationForm() {

        $form = Array();

        $sortedAccessories = self::$supportedAccessories;
        uasort($sortedAccessories, function($a, $b) {
            $posA = call_user_func(self::configurationClassPrefix . $a . "::getPosition");
            $posB = call_user_func(self::configurationClassPrefix . $b . "::getPosition");
            return ($posA < $posB) ? -1 : 1;
        });

        foreach($sortedAccessories as $accessory) {

            $columns = Array(
                Array(
                    "label" => "ID",
                    "name" => "ID",
                    "width" => "35px",
                    "add" => 0,
                    "save" => true
                ),
                Array(
                    "label" => "Name",
                    "name" => "Name",
                    "width" => "150px",
                    "add" => "",
                    "edit" => Array(
                        "type" => "ValidationTextBox"
                    )
                ), //We will insert the custom columns here
                Array(
                    "label" => "Status",
                    "name" => "Status",
                    "width" => "50px",
                    "add" => "-"
                )
            );

            array_splice($columns, 2, 0, call_user_func(self::configurationClassPrefix . $accessory . "::getColumns"));

            $values = Array();

            $datas = json_decode(IPS_GetProperty($this->instanceID, self::propertyPrefix . $accessory), true);
            foreach($datas as $data) {
                $values[] = Array(
                    "Status" => call_user_func(self::configurationClassPrefix . $accessory . "::getStatus", $data)
                );
            }

            $form[] = Array(
                "type" => "List",
                "name" => "Accessory" . $accessory,
                "caption" => call_user_func(self::configurationClassPrefix . $accessory . "::getCaption"),
                "rowCount" => 5,
                "add" => true,
                "delete" => true,
                "sort" => Array(
                    "column" => "name",
                    "direction" => "ascending"
                ),
                "columns" => $columns,
                "values" => $values
            );

        }

        return $form;

    }

    public function putCharacteristics($aid, $iid, $value) {

        if($aid == 1) {
            $class = self::classPrefix . "Bridge";
            $object = new $class();
            $object->setCharacteristic($iid, $value);
            return;
        }

        foreach(self::$supportedAccessories as $accessory) {
            $datas = json_decode(IPS_GetProperty($this->instanceID, self::propertyPrefix . $accessory), true);
            foreach($datas as $data) {
                if($aid == $data["ID"]) {
                    $class = self::classPrefix . $accessory;
                    $object = new $class($data);
                    $object->setCharacteristic($iid, $value);
                    return;
                }
            }
        }

        throw new Exception(sprintf("Cannot find accessory with ID %d", $aid));

    }

    public function getCharacteristics($aid, $iid) {

        if($aid == 1) {
            $class = self::classPrefix . "Bridge";
            $bridge = new $class();
            return $bridge->getCharacteristic($iid);
        }

        foreach(self::$supportedAccessories as $accessory) {
            $datas = json_decode(IPS_GetProperty($this->instanceID, self::propertyPrefix . $accessory), true);
            foreach($datas as $data) {
                if($aid == $data["ID"]) {
                    $class = self::classPrefix . $accessory;
                    $object = new $class($data);
                    return $object->getCharacteristic($iid);
                }
            }
        }

        throw new Exception(sprintf("Cannot find accessory with ID %d", $aid));

    }

}