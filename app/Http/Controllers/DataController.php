<?php namespace App\Http\Controllers;

use DB;
use Input;
use App\Chart;
use App\Variable;
use App\TimeType;
use App\Datasource;
use App\License;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Cache;

class DataController extends Controller {

	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index() {
		return "Controller for data";
	}

	public function dimensions( Request $request ) {

		set_time_limit( 10 ); 
		ini_set('memory_limit', '256M');

		//check we have everything we should have
		if( !Input::has( 'dimensions' ) ) {
			//we don't have necessary info, bail out
			return [ 'success' => false ];
		}
		
		//filtering by entities?
		$selectedCountriesIds = Input::get( "selectedCountries" );
		$selectedCountriesIdsString = ( !empty( $selectedCountriesIds ) && count( $selectedCountriesIds ) > 0 )? implode( ",", $selectedCountriesIds ) : "";
		//filtering by time?
		$chartTime = Input::get( "chartTime" );

		if( Input::has( 'chartId' ) ) {
			//caching - construct key with selected countries as well
			$key = 'chart-dimensions-' . Input::get( 'chartId' ). '-countries-' .$selectedCountriesIdsString;
			//if there's something in cache and not exporting
			if( Cache::has( $key ) && !Input::has( 'export' ) && ( Input::has( 'cache' ) && Input::get( 'cache' ) === "true" ) ) {
				//return Cache::get( $key );
			}
		}
		
		//set_time_limit( 600 ); 
		//ini_set('memory_limit', '256M');
		$data = array();
		
		//extra array for storing values for export
		$times = array();
		$datasourcesIdsArr = array();

		$dimensionsInput = Input::get( 'dimensions' );
		$dimensions = json_decode( $dimensionsInput );

		//isn't it just empty object
		if( empty( $dimensions ) ) {
			return [ 'success' => false ];
		}

		$chartType = Input::get( 'chartType' );

		//there's special setting for linechart
		$isLineChart = ( $chartType == "1" )? true: false;
		//temp 
		//$isLineChart = true;

		//find out how many variables we have 
		$groupByEntity = ( Input::get( 'groupByVariables' ) == 'false' )? true: false;
		//temp
		//$groupByEntity = false;

		//special case for linechart with multiple variables 
		$multiVariantByEntity = false;
		if( $groupByEntity && $isLineChart && count( $dimensions ) > 1 ) {
			$multiVariantByEntity = true;
		}

		$timeType = '';

		if( $groupByEntity ) {
			$entities = array();
			$dataByEntity = array();
			$dataByEntityTime = array();
		} else {
			$variables = array();
			$dataByVariable = array();
			$dataByVariableTime = array();
		}

		/**
		 * 1) get data into variable
		 **/

		//store the longest variable, will be used as main one
		$dimensionsByKey = [];
		$minDataLength = false;
		$mainDimId = false;
		$otherDimIds = [];

		foreach( $dimensions as $dimension ) {

			$id = $dimension->variableId;
			//use query builder instead of eloquent
			$variableQuery = DB::table( 'data_values' )
				->select( 'data_values.*', 'times.*', 'entities.name as name', 'variables.name as variable_name' )
				->join( 'entities', 'data_values.fk_ent_id', '=', 'entities.id' )
				->join( 'variables', 'data_values.fk_var_id', '=', 'variables.id' )
				->join( 'times', 'data_values.fk_time_id', '=', 'times.id' )
				->where( 'data_values.fk_var_id', $id );

			//are we filtering based on entity selection?
			if( !empty( $selectedCountriesIds ) && count( $selectedCountriesIds ) > 0 ) {
				$variableQuery->whereIn( 'data_values.fk_ent_id', $selectedCountriesIds );
			}
			//are we filtering based on time selection?
			if( !empty( $chartTime ) && count( $chartTime ) > 1 ) {
				$minTime = $chartTime[0];
				$maxTime = $chartTime[1];
				$variableQuery->where( 'times.date', '>=', $minTime );
				$variableQuery->where( 'times.date', '<=', $maxTime );
			}	

			$variableData = $variableQuery->get();

			//insert data into existing variable
			$dimension->data = $variableData;

			//is shortes variable?
			$dataLen = count( $variableData );
			if( $dataLen > $minDataLength || !$minDataLength ) {
				$minDataLength = $dataLen;
				$mainDimId = $id;
			}
			
		}

		/**
		 * 2) assign data to entities
		 **/
		foreach( $dimensions as $dimension ) {

			$id = $dimension->variableId;
			$property = $dimension->property;
			$variableData = $dimension->data;

			//store in array for step 3
			$dimensionsByKey[ $id ] = $dimension;
			if( $id != $mainDimId ) {
				$otherDimIds[] = $id;
			}

			//selectedCountries
			if( $groupByEntity ) {
			
				//group variable data by entities
				//$i = 0;
				$oldEntityId = -1;
				foreach( $variableData as $datum ) {

					//$entityId = $datum->fk_ent_id;
					$entityId = ( !$multiVariantByEntity )? $datum->fk_ent_id: $datum->fk_ent_id . "-" .$datum->fk_var_id;
					
					//check if new entity and we need to reset cycle
					if( $oldEntityId != $entityId ) {
						//$i = 0;
					}
					$oldEntityId = $entityId;
					
					//do we have already object for that entity
					if( !array_key_exists($entityId, $dataByEntity) ) {
						$key = ( !$multiVariantByEntity )? $datum->name: $datum->name . " - " . $datum->variable_name;
						$dataByEntity[ $entityId ] = array( 
							"id" => $entityId,
							//"id" => intval($entityId),
							"key" => $key,
							"values" => []
						);
					}
					
					//is it first property being saved for given property
					if( !array_key_exists( $property, $dataByEntity[ $entityId ][ "values" ] ) ) {
						$dataByEntity[ $entityId ][ "values" ][ $property ] = [];
					}
					//store value
					$dataByEntity[ $entityId ][ "values" ][ $property ][ floatval( $datum->date ) ] = floatval( $datum->value );
					
					//if is linechart, store time into x axis
					/*if( $isLineChart ) {
						$dataByEntity[ $entityId ][ "values" ][ $i ][ "x" ] = floatval( $datum->date );
					}
					$i++;*/

					//store time type if not stored
					if( empty( $timeType ) ) {
						$timeType = TimeType::find( $datum->fk_ttype_id )->name; 
					}

					//store for the need of export 
					if( !array_key_exists($entityId, $dataByEntityTime) ) {
						$dataByEntityTime[ $entityId ] = [];
						$entities[ $entityId ] = $datum->name; 
					}
					$dataByEntityTime[ $entityId ][ $datum->label ] = $datum->value;
					$times[ floatval( $datum->date ) ] = true;
					$datasourcesIdsArr[ $datum->fk_dsr_id ] = true;

				}

			} else {

				//multivariables

				//get variable names
				$variable = Variable::find( $dimension->variableId );

				$key = ( !empty( $variable ) && isset( $variable->name ) )? $variable->name: "";
				$dataByVariable[ "id-".$id ] = array( 
					"id" => $id,
					"key" => $key,
					"values" => []
				);

				//store variable name to dimension info (useful for stack bar chart)
				$dimensionsByKey[ $id ]->variableName = $key;
				
				foreach( $variableData as $datum ) {
					
					$dataByVariable[ "id-".$id ][ "values" ][] = array( "x" => floatval($datum->date), "y" => floatval($datum->value) );
					$times[$datum->label] = true;
					$datasourcesIdsArr[ $datum->fk_dsr_id ] = true;

					//store time type if not stored
					if( empty( $timeType ) ) {
						$timeType = TimeType::find( $datum->fk_ttype_id )->name; 
					}

					//store for the need of export 
					if( !array_key_exists($dimension->variableId, $dataByVariableTime) ) {
						$dataByVariableTime[ $dimension->variableId ] = [];
						$variables[ $dimension->variableId ] = $datum->fk_var_id; 
					}
					$dataByVariableTime[ $dimension->variableId ][ $datum->label ] = $datum->value;

				}
				
			}

		}


		/**
		 * 3) prepare array for different chart types
		 **/

		//$normalizedData = [];
		$mainDimension = $dimensionsByKey[ $mainDimId ];
		if( $groupByEntity ) {
			$normalizedData = Chart::formatDataForChartType( 3, $dataByEntity, $dimensionsByKey, $times );
		} else {
			$dataByVariable = Chart::formatDataForChartType( 3, $dataByVariableTime, $dimensionsByKey, $times, true );
		}
			
		//loop through all countries
		/*if( $groupByEntity ) {

			foreach( $dataByEntity as $entityData ) {
				
				$arr = array(
					"id" => $entityData[ "id" ],
					"key" => $entityData[ "key" ],
					"values" => []
				);

				//main values
				//do we have some values for given entity at all?
				if( !array_key_exists( $mainDimension->property, $entityData[ "values" ] ) ) {
					//nope, bail on this entity
					continue;
				}

				$mainValues = $entityData[ "values" ][ $mainDimension->property ];
				$i = 0;

				//settings for parameters
				$defaultPeriod = "all";
				$period = ( isset( $mainDimension->period ) )? $mainDimension->period: $defaultPeriod; 
				
				//depending on the mode, continue with the rest
				if( $period === "single" ) {
					
					//only getting one value per country per specify value
					$hasData = true;
					$timeArr = [];

					foreach( $dimensionsByKey as $dimension ) {

						$defaultMode = "specific";
						$defaultYear = 2000;
						$mode = ( isset( $dimension->mode ) )? $dimension->mode: $defaultMode; 

						if( $mode === "specific" ) {
						
							$time = ( isset( $dimension->targetYear ) )? $dimension->targetYear: $defaultYear;
						
						} else if( $mode === "latest" ) {
						
							//need to fetch latest year for given property

							//do we have some values for given entity and property at all?
							if( isset( $entityData[ "values" ][ $dimension->property ] ) ) {
								$allYears = array_keys( $entityData[ "values" ][ $dimension->property ] );
								$latestYear = max( $allYears );
								$time = $latestYear;
							} else {
								$hasData = false;
								continue;
							}
						
						}

						//store time if main property
						if( $dimension->variableId === $mainDimension->variableId ) {
							$timeArr[ "time" ] = $time;
						}
						
						//try to find value for given dimension, entity and time
						if( array_key_exists( $dimension->property, $entityData[ "values" ] ) ) {

							$value = $this->getValue( $dimension, $time, $entityData[ "values" ][ $dimension->property ] );
							if( $value ) {
								$timeArr[ $dimension->property ] = $value; 
							} else {
								$hasData = false;
							}
							
						} else {
							$hasData = false;
						}

					}

					$arr[ "values" ][ 0 ] = $timeArr;
					if( $hasData ) {
						$normalizedData[ $entityData[ "id" ] ] = $arr;
					}
				
				} else {

					//case when getting data for whole range of values
					foreach( $mainValues as $time=>$mainValue ) {

						//array where we store data for all properties for given time 
						$timeArr = [];

						//flag whether for given time, there's enough relevant data
						$hasData = true;

						//take value from 
						$timeArr[ $mainDimension->property ] = $mainValue;
						//store time as one dimension, usefull for popup for scatter plot
						$timeArr[ "time" ] = $time;

						//insert other properties for given main property
						foreach( $otherDimIds as $otherDimId ) {

							$otherDimension = $dimensionsByKey[ $otherDimId ];

							$value = false;
							//retrieve value for property
							//has property any values at all?
							if( !empty( $entityData[ "values" ][ $otherDimension->property ] ) ) {
								
								$defaultMode = "closest";
								$mode = ( isset( $otherDimension->mode ) )? $otherDimension->mode: $defaultMode;
								
								if( $mode === "latest" ) {
									$allYears = array_keys( $entityData[ "values" ][ $otherDimension->property ] );
									$latestYear = max( $allYears );
									$time = $latestYear;
								}

								//try to find value for given dimension, entity and time
								if( array_key_exists( $otherDimension->property, $entityData[ "values" ] ) ) {

									$value = $this->getValue( $otherDimension, $time, $entityData[ "values" ][ $otherDimension->property ] );
									if( $value ) {
										$timeArr[ $otherDimension->property ] = $value; 
									} else {
										//temp
										//$value = 0;
										$hasData = false;
									}
									
								} else {
									$hasData = false;
								}
							
							} 

							if( !$value ) {
								$hasData = false;
								$value = 0;
							}
							$timeArr[ $otherDimension->property ] = $value;
							
						}

						//if is linechart, has only one dimension
						if( $isLineChart ) {
							$timeArr[ "x" ] = $time;
						}

						//if is valid array, insert
						if( $hasData ) {

							//are we matching agains entity and time, or only against entity
							$onlyEntityMatch = ( Input::has( "onlyEntityMatch" ) && Input::get( "onlyEntityMatch" ) === "true" )? true: false;
							if( !$onlyEntityMatch ) {
								$arr[ "values" ][ $i ] = $timeArr;
							} else {
								//storing only one array for each country and entity
								$arr[ "values" ][ 0 ] = $timeArr;
							}
							$i++;

						} 
						
					}

					$normalizedData[ $entityData[ "id" ] ] = $arr;
					
				}

			}
				
		}*/
		
		if( $groupByEntity ) {
			//convert to array
			foreach( $normalizedData as $entityData ) {
				//TODO better check for this?
				if( $entityData[ "values" ] ) {
					$data[] = $entityData;
				}
			}
		} else {
			//convert to array
			foreach( $dataByVariable as $varData ) {
				$data[] = $varData;
			}
		}

		/**
		 *	4) fetch all the other necessary data
		 **/


		//get all necessary info for datasources
		$datasourcesIds = array_keys( $datasourcesIdsArr );
		$datasources = Datasource::findMany( $datasourcesIds );

		//process data to csv friendly format
		$timeKeys = array_keys( $times );
		//sort timeKeys by time
		usort( $timeKeys, function ($a, $b) { if ( $a==$b ) return 0; else return ($a > $b) ? 1 : -1; });
		
		//get all the licence information
		$license = License::find( 1 )->first();

		//construct first row
		$firstRow = $timeKeys;
		array_unshift( $firstRow, "Times" ); 

		$exportData = [ $firstRow ];

		if( $groupByEntity ) {

			foreach( $dataByEntityTime as $entityId=>$entityData ) {
				//first insert name
				$entityName = ( array_key_exists($entityId, $entities) )? $entities[$entityId]: "";
				$rowData = [ $entityName ];
				//then insert times
				foreach( $timeKeys as $time ) {
					//does value exist for given time and entity?
					if( !array_key_exists($time, $entityData) ) {
						//insert blank value
						$rowData[] = ""; 
					} else {
						//value exists
						$rowData[] = $entityData[$time];
					} 
				}
				$exportData[] = $rowData;
			}

		} else {

			foreach( $dataByVariableTime as $variableId=>$variableData ) {
				//first insert name
				$variableName = ( array_key_exists($variableId, $variables) )? $variables[$variableId]: "";
				$rowData = [ $variableName ];
				//then insert times
				foreach( $timeKeys as $time ) {
					//does value exist for given time and entity?
					if( !array_key_exists($time, $variableData) ) {
						$rowData[] = "x"; 
					} else {
						//value exists
						$rowData[] = $variableData[$time];
					} 
				}
				$exportData[] = $rowData;
			}

		}

		if( $request->ajax() ) {

			$result = [ 'success' => true, 'data' => $data, 'datasources' => $datasources, 'timeType' => $timeType, 'exportData' => $exportData, 'license' => $license ];
			
			//store into cache - there is no cache 
			if( !empty( $key ) ) {
				$minutes = 60*24;
				Cache::put( $key, $result, $minutes );
			}
			
			return $result;

		} else {

			if( Input::has( 'export' ) && Input::get( 'export' ) == 'csv' ) {
				
				//http://localhost:8888/oxford/our-world-in-data-chart-builder/public/data/dimensions?dimensions=%5B%7B%22variableId%22%3A%221%22%2C%22property%22%3A%22y%22%2C%22name%22%3A%22Y+axis%22%7D%5D
				//return $data;
				return $this->downloadCsv( $exportData );
			
			} else {

				//not ajax request, nor csv export, just spit out whatever is in data
				return $data;

			}

		}

	}

	public function downloadCsv( $data ) {

		$fileName = 'data-' .date('Y-m-d H:i:s'). '.csv';
		$headers = [
			'Cache-Control'	=>	'must-revalidate, post-check=0, pre-check=0',
			'Content-type' => 'text/csv',
			'Content-Disposition' => 'attachment; filename=' .$fileName,
			'Expires' => '0',
			'Pragma' => 'public'
		];

		$csv = \League\Csv\Writer::createFromFileObject(new \SplTempFileObject());
		foreach($data as $datum) {
            $csv->insertOne($datum);
        }
        $csv->output( $fileName );
        //have to die out, for laravel not to append non-sense
		die();
	
	}

	public function entities( Request $request ) {

		$data = array();
		if( !Input::has( 'variableIds' ) ) {
			return [];
		}
		
		$variableIdsInput = Input::get( 'variableIds' );
		$variableIds = explode( ',', $variableIdsInput );

		//use query builder instead of eloquent
		$entitiesData = DB::table( 'data_values' )
			->select( 'entities.id', 'entities.name' )
			->join( 'entities', 'data_values.fk_ent_id', '=', 'entities.id' )
			->whereIn( 'data_values.fk_var_id', $variableIds )
			->groupBy( 'name' )
			->get();

		$data = $entitiesData;

		if( $request->ajax() ) {

			return ['success' => true, 'data' => $data ];

		} else {
			//not ajax request, just spit out whatever is in data
			return $data;
		}

	}

	public function times( Request $request ) {

		$data = array();
		if( !Input::has( 'variableIds' ) ) {
			return [];
		}

		$variableIdsInput = Input::get( 'variableIds' );
		$variableIds = explode( ',', $variableIdsInput );

		//use query builder instead of eloquent
		$timesData = DB::table( 'data_values' )
			->select( 'times.id', 'times.date', 'times.label' )
			->join( 'times', 'data_values.fk_time_id', '=', 'times.id' )
			->whereIn( 'data_values.fk_var_id', $variableIds )
			->groupBy( 'date' )
			->get();

		$data = $timesData;

		if( $request->ajax() ) {

			return ['success' => true, 'data' => $data ];

		} else {
			//not ajax request, just spit out whatever is in data
			return $data;
		}

	}

	public function getValue( $dimension, $time, $values ) {

		$value;
		//do we have value for exact time
		if( array_key_exists( $time, $values ) ) {
			
			if( $dimension->mode === "latest" && isset( $dimension->maximumAge ) ) {
				//for latest, we ahave to check the latest avaiable data is not too old
				$nowTime = date( "Y" );
				$oldestAllowedTime = $nowTime - $dimension->maximumAge;
				if( $time < $oldestAllowedTime ) {
					//latest available time is too old, bail
					return;
				}
			} 

			$value = $values[ $time ];
			
		} else {
			//no we don't, try to around in recent years
			if( $dimension->mode !== "latest" ) {
				$value = $this->lookAround( $dimension, $time, $values );
			}
		}

		return $value;

	}

	public function lookAround( $dimension, $time, $values ) {

		$defaultTolerance = 5;
		$lookAroundLen = $defaultTolerance;

		//find out if we'll be looking in past and future (case for specific year with tolerance ), or only past (case for latest date with maximum age)
		$direction = ( isset( $dimension->mode ) && $dimension->mode == "latest" )? "past": "both";
		//set look around len depending on mode
		if( isset( $dimension->mode ) ) {
			if( $dimension->mode === "latest" && isset( $dimension->maximumAge ) ) {
				//for latest, set check latest time if it's within allowed age and set tolerance to zero
				//$lookAroundLen = $dimension->maximumAge;
				$lookAroundLen = 0;//$dimension->maximumAge;
				$nowTime = date( "Y" );
				$oldestAllowedTime = $nowTime - $dimension->maximumAge;
				return false;
				if( $time < $oldestAllowedTime ) {
					//latest available time is too old, bail
					return false;
				}
			}
			if( ( $dimension->mode === "specific" || $dimension->mode === "closest" ) && isset( $dimension->tolerance ) ) {
				$lookAroundLen = $dimension->tolerance;
			}
		} 
		$currLen = 0;
		$currLook = $lookAroundLen;
		
		$origTime = $time;
		$currTime = $time;

		while( $currLen < $lookAroundLen ) {

			//increase gap
			$currLen++;
			
			//try going forward first
			$currTime = $origTime + $currLen;
			//break if found value
			if( array_key_exists( $currTime, $values ) ) {
				$value = $values[ $currTime ]; 
				return $value;
			}

			//nothing forward, trying going backward
			$currTime = $origTime - $currLen;
			//break if found value
			if( array_key_exists( $currTime, $values ) ) {
				$value = $values[ $currTime ]; 
				return $value;
			}

		}
	}

	public function exportToSvg( Request $request ) {
		
		$svg = 'Export to svg failed';
		if( Input::has( 'export-svg' ) ) {
			$svg = $request->input( 'export-svg' );
		} 
		$type = 'image/svg+xml';
		return response( $svg )->header('Content-Type',$type);
	}


}
