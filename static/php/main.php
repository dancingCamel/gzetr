<?php

    // enable error reporting. 
    ini_set('display_errors', 'On');
    error_reporting(E_ALL);

    include('restcountries.php');
    include('geocoder.php');
    include('climatedata.php');
    include('factbook.php');
    include('geo.php');
    include('db.php');

    // accept country as parameter from request and save as variable
    $country = $_GET['country'];

    $dbConn = new CountryDb();

    $existsInDb = false;
    $dbEntry = $dbConn->selectCountry($country);
    
    if(gettype($dbEntry)==='array'){
        if (count($dbEntry) > 0){
            $existsInDb = true;

            // check expiry date
            $current_timestamp = time();
            if ($current_timestamp > $dbEntry[0]['expiry']){
                goto getdata;
            }
            $jsonEncoded = $dbEntry[0]['data'];
            goto end;
        }
    }     

    getdata:
    // if less than 4 letters, search with rest country alpha 2 static method. else full name search
    if (strlen($country) < 4){
        $rc_result = RestCountries::findByAlpha2($country);
        $rc_decode = json_decode($rc_result,true);
        $output = handleErrorIfExists($rc_decode);
        if ($output){
            $jsonEncoded = json_encode($output, JSON_UNESCAPED_UNICODE);
            goto end;
        }
    } else {
        $rc_result = RestCountries::findByName($country);
        $rc_decode = json_decode($rc_result,true);
        $output = handleErrorIfExists($rc_decode);
        if ($output){
            $jsonEncoded = json_encode($output, JSON_UNESCAPED_UNICODE);
            goto end;
        }
        $rc_decode = $rc_decode[0];
    }


    // use rest country to search opencage static method
    $oc_result = Geolocation::getDataByName($rc_decode['name']);
    $oc_decode = json_decode($oc_result,true);
    $output = handleErrorIfExists($oc_decode);
    if ($output){
        $jsonEncoded = json_encode($output, JSON_UNESCAPED_UNICODE);
        goto end;
    }

    // use rest country to search climate data api static method
    if ($rc_decode['capital'] === ""){
        $climate_result = ClimateData::getClimateData($oc_decode['geometry']['lat'], $oc_decode['geometry']['lng']);
    } else {
        $capital_result = Geolocation::getDataByName($rc_decode['capital']);
        $capital_decode = json_decode($capital_result,true);
        $climate_result = ClimateData::getClimateData($capital_decode['geometry']['lat'], $capital_decode['geometry']['lng']);
    }
    $climate_decode = json_decode($climate_result,true);


    // use opencage country name response to search factbook static method
    $fb_decode = Factbook::getDataByCountry($oc_decode['components']['country']);
    $output = handleErrorIfExists($fb_decode);
    if ($output){
        $jsonEncoded = json_encode($output, JSON_UNESCAPED_UNICODE);
        goto end;
    }

    $gj_decode = GeoJson::getGeoJson($rc_decode['alpha3Code']);
    
    $output['clim'] = ClimateData::formatClimateData($climate_decode);
    $gotClimateData = $output['clim'] === "No Data" ? false : true;

    $output['rc'] = RestCountries::formatRcData($rc_decode);
    $output['oc'] = Geolocation::formatOcData($oc_decode);
    $output['fb'] = Factbook::formatFbData($fb_decode);
    
    $output['geo'] = $gj_decode;

    $output['status']['code'] = 200;
    $output['status']['name'] = "success";

    $jsonEncoded = json_encode($output, JSON_UNESCAPED_UNICODE);

    // save to database here
    if($existsInDb){
        // update
        if ($gotClimateData){
            $dbConn->updateCountry($rc_decode['name'],$rc_decode['alpha2Code'], $jsonEncoded);
        } else {
            $dbConn->updateCountry($rc_decode['name'],$rc_decode['alpha2Code'], $jsonEncoded, false);
        }
    } else {
        // insert
        if ($gotClimateData){
            $dbConn->insertCountry($rc_decode['name'],$rc_decode['alpha2Code'], $jsonEncoded);
        } else {
            $dbConn->insertCountry($rc_decode['name'],$rc_decode['alpha2Code'], $jsonEncoded, false);
        }
    }


    end:
    header('Content-Type: application/json; charset=UTF-8');
    echo $jsonEncoded;
    

    // helper functions
    function handleErrorIfExists($data){
        if (is_array($data)){
            if (array_key_exists('status', $data)){
                $output['status']['code'] = $data['status'];
                $output['status']['name'] = 'error';
                $output['message'] = $data['message'];
                return $output;
            } else if (array_key_exists('errorCode', $data)){
                $output['status']['code'] = $data['errorCode'];
                $output['status']['name'] = "error";
                $output['message'] = $data['message'];
                return $output;
            }
        } 

        if (gettype($data) == "object"){
            if (property_exists($data, 'status')){
                $output['status']['code'] = $data['status'];
                $output['status']['name'] = "error";
                $output['message'] = $data['message'];
                return $output;
            } 
        }

        return false;
        
    }
?>