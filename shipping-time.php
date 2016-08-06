<?php

// Initial setup
if ( ! defined('PUBLIC_CODE') ) exit( 'Hacking attempt?' );
define( 'API_ROOT', "/var/www/ds_api" );
require_once( API_ROOT . '/vendor/autoload.php' );
use GeoIp2\Database\Reader;



// Helper functions 

// extend() will combine 2 arrays by replacing the values of array1 with values from array2 of matching keys
function extend($base = array(), $replacements = array()) {
    $base = ! is_array($base) ? array() : $base;
    $replacements = ! is_array($replacements) ? array() : $replacements;

    return array_replace_recursive($base, $replacements);
}



class VisitorLocation {
	
	public $userIP, $reader, $output;
	
	public function __construct() {
		//$this->userIP = "209.95.50.104";
		$this->userIP = $this->getUserIP();
		$this->userLoc = $this->getUserLocation();
		$this->country_name = $this->userLoc->country->name;
		$this->country_code = $this->userLoc->country->isoCode;
		$this->state_name = $this->userLoc->mostSpecificSubdivision->name;
		$this->state_code = $this->userLoc->mostSpecificSubdivision->isoCode;
		$this->city_name = $this->userLoc->city->name;
		$this->postal_code = $this->userLoc->postal->code;
		$this->latitude = $this->userLoc->location->latitude;
		$this->longitude = $this->userLoc->location->longitude;
	}

	// Get Visitor IP Address
	public function getUserIp() {
	    $client  = @$_SERVER['HTTP_CLIENT_IP'];
	    $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
	    $remote  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "127.0.0.1";
	
	    if ( filter_var($client, FILTER_VALIDATE_IP) ) {
	        $userIP = $client;
	    } elseif ( filter_var($forward, FILTER_VALIDATE_IP) ) {
	        $userIP = $forward;
	    } else {
	        $userIP = $remote;
	    }
	
	    return $userIP;
	}
	
	// Get visitor GeoIP
	public function getUserLocation() {
		$reader = new Reader( API_ROOT . '/GeoIP/GeoLite2-City.mmdb');

		if ( $this->userIP != "127.0.0.1" ) {
			$geo_ip_record = $reader->city( $this->userIP );
			return $geo_ip_record;
		} else {
			$geo_ip_record = $reader->city( "209.95.50.104" );
			echo "Running GeoIP Script from command line. \n";
			return $geo_ip_record;
		}
	}
};



class ShippingTime {
	
	// Shipping time configuration 
	public $shippingDefaults, $shippingOptions, $shippingConfig, $deadline, $userloc;
	
	public function __construct ( $shippingOptions ) {
		$this->shppingDefaults  = json_decode('{
				"timezone": "-0400",
				"deadline": {
					"hour": 12,
					"minute": 0
				},
				"shipMethodName": "Free Shipping",
				"noShipping": "12/24,12/25",
				"leadtime": {
					"Alabama": 3,
					"Alaska": 4,
					"Arizona": 3,
					"Arkansas": 3,
					"California": 4,
					"Colorado": 3,
					"Connnecticut": 3,
					"Delaware": 3,
					"Florida": 2,
					"Georgia": 2,
					"Hawaii": 5,
					"Idaho": 3,
					"Illinois": 3,
					"Indiana": 4,
					"Iowa": 4,
					"Kansas": 4,
					"Kentucky": 3,
					"Louisiana": 3,
					"Maine": 3,
					"Maryland": 3,
					"Massachusetts": 3,
					"Michigan": 4,
					"Minnesota": 3,
					"Mississippi": 3,
					"Missouri": 4,
					"Montana": 4,
					"Nebraska": 4,
					"Nevada": 3,
					"New Hampshire": 3,
					"New Jersey": 3,
					"New Mexico": 4,
					"New York": 3,
					"North Carolina": 3,
					"North Dakota": 4,
					"Ohio": 3,
					"Oklahoma": 4,
					"Oregon": 4,
					"Pennsylvania": 3,
					"Rhode Island": 3,
					"South Carolina": 3,
					"South Dakota": 4,
					"Tennessee": 3,
					"Texas": 4,
					"Utah": 4,
					"Vermont": 3,
					"Virginia": 3,
					"Washington": 3,
					"West Virginia": 3,
					"Wisconsin": 3,
					"Wyoming": 4
				}
			}', true);
		$this->shppingOptions = $shippingOptions;
		$this->shippingConfig = $this->shippingConfig( $this->shppingDefaults, $shippingOptions );
		$this->timezone = $this->shippingConfig()["timezone"];
		$this->moment = new \Moment\Moment( "now", $this->timezone );
	}

	public function shippingConfig( $shippingConfigDefaults = null, $shippingConfigOptions = null ) {
		$shippingConfigDefaults = isset($shippingConfigDefaults) ? $shippingConfigDefaults : $this->shppingDefaults;
		$shippingConfigOptions = isset($shippingConfigOptions) ? $shippingConfigOptions : $this->shppingOptions;
		$shippingConfig = extend( $shippingConfigDefaults, $shippingConfigOptions );
		return $shippingConfig;
	}
	
	public function isWeekDay( $thisMoment ) {
		return ($thisMoment->getWeekday() != 6 && $thisMoment->getWeekday() != 7) ? true : false;
	}
	
	public function isExcluded( $thisMoment ) {
		$excludedDates = explode(',', $this->shippingConfig()["noShipping"]);
		foreach ($excludedDates as $date) {
			if ( $thisMoment->format("M/D") == $date ) {
				return true;
			}
		}
		return false;
	}
	
	public function shipDay( $deadline ) {
		return $this->moment->isSame( $deadline, "day" ) ? "Today" : $deadline->format("l");
	}
	
	public function shippingDeadline($hour, $minute) {
		$deadlineMoment = new \Moment\Moment("now", $this->timezone);
		$deadline = $deadlineMoment->setTime($hour, $minute);
        while ($this->isExcluded($deadline)) {
            $deadline->addHours(24);
        }
        if ( $this->moment->getHour() >= 
        	$deadline->getHour() && $this->isWeekday( $this->moment ) ) {
            $deadline->addHours(24);
        }
        if ( !$this->isWeekday( $deadline ) ) {
            return ( $deadline->getDay() === 0 ) ? $deadline->addHours(24) : $deadline->addHours(48);
        } else {
            return $deadline;
        }
	}
	
	public function timeUntilDeadline( $deadline ) {
		$minutesTill = abs( $this->moment->from($deadline)->getMinutes() );
        for ($hoursTill = 0; $minutesTill >= 60; $hoursTill++) {
            $minutesTill -= 60;
        }
        $timeUntilDeadline = (object) array( 'hours' => $hoursTill, 'minutes' => $minutesTill );
        return $timeUntilDeadline;
	}
	
	public function projectedDeliveryDate( $deliveryTime, $userState ) {
        $estimateDay = $deliveryTime->addDays($this->shippingConfig()["leadtime"]["{$userState}"]);
        while ($this->isExcluded($estimateDay)) {
            $estimateDay->addHours(24);
        }
        if ( !$this->isWeekday( $estimateDay ) ) {
            return ( $estimateDay->getDay() === 0 ) ? $estimateDay->addHours(24) : $estimateDay->addHours(48);
        } else {
            return $estimateDay;
        }
	}
	
	public function displayShipTime() {
		$deadline = $this->shippingDeadline( $this->shippingConfig()["deadline"]["hour"], $this->shippingConfig()["deadline"]["minute"] );
		$timeLeft = $this->timeUntilDeadline( $deadline );
		
		if ( $this->shippingConfig()["leadtime"] ) {
			$userLoc = new VisitorLocation();
			if ( $userLoc->country_code == "US" && $userLoc->state_name !== null) {

				if ( !headers_sent() ) {
					header('Access-Control-Allow-Origin: *');
					header('Content-Type: application/json');
				}

				$hoursLeft = $timeLeft->hours;
				$minutesLeft = $timeLeft->minutes;
				$shipDay = $this->shipDay($deadline);				
				$projectedDeliveryDate = $this->projectedDeliveryDate( $deadline, $userLoc->state_name )->format('l, F dS');
				$state = $userLoc->state_name;
				$shippingMethod = $this->shippingConfig()["shipMethodName"];
/*
				var_dump($hoursLeft);
				var_dump($minutesLeft);
				var_dump($shipDay);
				var_dump($projectedDeliveryDate);
				var_dump($state);
				var_dump($shippingMethod);
*/
				
				$shippingDeliveryArray = array(
					"hoursLeft" => $hoursLeft, 
					"minutesLeft" => $minutesLeft, 
					"shipDay" => $shipDay, 
					"projectedDelivery" => $projectedDeliveryDate, 
					"userState" => $state, 
					"shippingMethod"=> $shippingMethod
					);
// 				var_dump($shippingDeliveryArray);
					
				$shippingDeliveryJSON = json_encode($shippingDeliveryArray);
// 				var_dump($shippingDeliveryJSON);
				
				//echo "Est Delivery: {$projectedDeliveryDate} to {$state} via {$shippingMethod}";				

				echo $shippingDeliveryJSON;
			}
		}
	}

};


