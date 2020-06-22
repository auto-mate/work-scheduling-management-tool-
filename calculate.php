<?php

include_once("globals.php");
/* "globals.php" contents....

$GLOBALS[ 'UID' ]        = "<uid>";
$GLOBALS[ 'PWD' ]        = "<password>"; 
$GLOBALS[ 'Database' ]   = "<dbName>";  
$GLOBALS[ 'serverName' ] = "<serverName>";
*/


function calcShed() {
  
  getSQLAction("DELETE FROM scheduleActual","stCreateAvail","con1");
  getSQLAction(crtAvlblAndWrkAvlb(),"stCreateAvail","con2");   
  
  /* GET PROJECTS IN HIERARCHY ORDER */
  if ( getSQLOpen("SELECT * FROM [project] WHERE [Status] like '%In Progress%' ORDER BY [Hierarchy]","stPrj","Con3")) {
    while ( $row = sqlsrv_fetch_array( $GLOBALS['stPrj'], SQLSRV_FETCH_ASSOC )) {
        
        PreAllocate($row["projectRef"]);
        
      }
    }
  }

  


function PreAllocate( $ref ) {
  
  $floatDays      = 0;
  $OkToUpdate     = True;
  $fullyAllocated = False;

  //GET END DATE
  if (getSQLOpen("SELECT Max([date]) AS md FROM [scheduleIdeal] WHERE projectRef = " . $ref . " AND [Complete] = 'N' ","stMaxDte","Con4")){
    while ( $rowMD = sqlsrv_fetch_array( $GLOBALS['stMaxDte'], SQLSRV_FETCH_ASSOC )) {
      $prjEndDate = $rowMD["md"];
    }
  }
  // Cycle Steps
  if (getSQLOpen("SELECT * FROM [scheduleIdeal] WHERE projectRef = " . $ref . " AND [Complete] = 'N' ORDER BY [Step] DESC","stSteps","stCon")) {
    while ( $row = sqlsrv_fetch_array( $GLOBALS['stSteps'], SQLSRV_FETCH_ASSOC )) {

      // Get DEPENDANCIES
      if (getSQLOpen("SELECT * FROM [dependancies] WHERE projectRef = " . $ref . " AND [dependantOnStep] = " . $row["step"],"stDep","conDep")) {
        if ( $GLOBALS['stDeprowCnt'] > 0 ) {
          // Has Dependancy
          echo "Has Dependancy";
      } else {
          // Has No Dependancy
            $reqHrs = $row["Hours"];
            $dev    = $row["dev"];
            $diffDays = new DateInterval("P".$floatDays."D");
            $finBy  = date_add($prjEndDate,$diffDays);
            $pStep  = $row["step"];
            $Result = Allocate($ref, $pStep, $finBy, $dev, $reqHrs);
            
            if ($Result == "Error" ) {
              $OkToUpdate = False;
              break;
            }             
            
          echo "Has NO Dependancy";
      }


      echo $row["step"]."<br>";
    }
  }

}
}

function Allocate($ref, $pStep, $finBy, $dev, $reqHrs) {
  
}

function crtAvlblAndWrkAvlb() {
  $crtAvlblAndWrkAvlb = 
  "
  SET NoCount ON


  USE ProjectSchedule;


  DECLARE @TO DATE;
  DECLARE @FROM DATE;
  DECLARE @WRKING_DTE DATE ;
  DECLARE @DEVCOUNT INT;
  DECLARE @COUNT INT;
  DECLARE @DEVNAME VARCHAR(30);

  SELECT @TO   = DATEADD(MONTH,6, MAX(P.[Required By Date])) From project P
  SELECT @FROM = CURRENT_TIMESTAMP
  SELECT @DEVCOUNT = COUNT(*) FROM developer
  SET    @WRKING_DTE = @FROM
  SET    @COUNT = 1

  IF OBJECT_ID('tempDb..#WorkingAvailability','U') IS NOT NULL
  BEGIN
    DROP TABLE #WorkingAvailability
  END;

  IF OBJECT_ID('tempDb..#Availability','U') IS NOT NULL
  BEGIN
    DROP TABLE #Availability
  END;


  CREATE TABLE #WorkingAvailability ( 
  [Date] date NOT NULL,
  [developer] nchar(40),
  [Hours] float
  );

  /* Make Calendar With Dates and Developers */
  WHILE @COUNT < @DEVCOUNT
  BEGIN;
    SELECT @DEVNAME=developer FROM  (SELECT ROW_NUMBER() OVER (ORDER BY [developer]) AS RW,* FROM [developer]) WITHROWCOUNTER WHERE RW = @COUNT;
    
    WHILE @TO > @WRKING_DTE
    BEGIN;
      INSERT INTO #WorkingAvailability SELECT @WRKING_DTE , @DEVNAME,0;
      SET @WRKING_DTE = DATEADD(DAY,1,@WRKING_DTE);
    END;
    SET @WRKING_DTE = @FROM;
    SET @COUNT =  @COUNT + 1;
  END;

  /* ADD STD HOURS */
  UPDATE 
    #WorkingAvailability 
  SET 
    #WorkingAvailability.Hours = stdWorkHrs.Hours
  FROM
    #WorkingAvailability 
  LEFT JOIN
    stdWorkHrs
  ON
    SUBSTRING(UPPER(DATENAME(DW,[DATE])),1,3) = stdWorkHrs.DayOfWeek;

  /* REM HOLS HOURS */
  UPDATE 
    #WorkingAvailability 
  SET 
    #WorkingAvailability.Hours = #WorkingAvailability.Hours - ISNULL(hols.Hours,0)
  FROM
    #WorkingAvailability 
  LEFT JOIN
    hols
  ON
    #WorkingAvailability.developer=hols.Developer AND
    #WorkingAvailability.Date=hols.Date;



  /* REM otherNonAvailability HOURS */
  UPDATE 
    #WorkingAvailability 
  SET 
    #WorkingAvailability.Hours = #WorkingAvailability.Hours - ISNULL(otherNonAvailability.Hours,0)
  FROM
    #WorkingAvailability 
  LEFT JOIN
    otherNonAvailability
  ON
    #WorkingAvailability.developer=otherNonAvailability.Developer AND
    #WorkingAvailability.Date=otherNonAvailability.Date;


  /* REM stdMeetings HOURS */
  UPDATE 
    #WorkingAvailability 
  SET 
    #WorkingAvailability.Hours = IIF(#WorkingAvailability.Hours - ISNULL(stdWeeklyMeetings.Hours,0)<0,0,#WorkingAvailability.Hours - ISNULL(stdWeeklyMeetings.Hours,0))
  FROM
    #WorkingAvailability 
  LEFT JOIN
    stdWeeklyMeetings
  ON
    SUBSTRING(UPPER(DATENAME(DW,[DATE])),1,3) = stdWeeklyMeetings.DayOfWeek AND
    #WorkingAvailability.developer = stdWeeklyMeetings.Developer;

  SELECT * INTO #Availability FROM #WorkingAvailability;
  ";
  return $crtAvlblAndWrkAvlb;
}

function getSQLAction( $sql,$stmt,$con ) {  

  $GLOBALS[ 'connection' ] = array( "UID"      => $GLOBALS[ 'UID' ], 
                                    "PWD"      => $GLOBALS[ 'PWD' ], 
                                    "Database" => $GLOBALS[ 'Database' ]);        

  $GLOBALS[ $con ] = sqlsrv_connect( $GLOBALS[ 'serverName' ] ,  
                                       $GLOBALS[ 'connection' ]);

  /// CHECK CONNECTION IS OK
  if ( $GLOBALS[ $con ] === false ) {
      echo "error!";
      die( print_r( sqlsrv_errors(), true ) );
      return false;  
  }
  else
  {        
      $GLOBALS[ $stmt        ] = sqlsrv_query(          $GLOBALS[$con], $sql );
      if (!$GLOBALS[$stmt]) {
        $GLOBALS[ 'recAffected' ] = null;
        } else 
        {
        $GLOBALS[ 'recAffected' ] = sqlsrv_rows_affected  ( $GLOBALS[$stmt] );
        }
      return true; 		  
  }
}

function getSQLOpen( $sql,$stmt,$con ) {  
  
  $GLOBALS[ 'connection' ] = array( "UID"      => $GLOBALS[ 'UID' ], 
                                    "PWD"      => $GLOBALS[ 'PWD' ], 
                                    "Database" => $GLOBALS[ 'Database' ]);        

  $GLOBALS[ $con ] = sqlsrv_connect( $GLOBALS[ 'serverName' ] ,  
                                       $GLOBALS[ 'connection' ]);

  /// CHECK CONNECTION IS OK
  if ( $GLOBALS[ $con ] === false ) {
      echo "error!";
      die( print_r( sqlsrv_errors(), true ) );
      return false;  
  }
  else
  {        
      $GLOBALS[ $stmt           ] = sqlsrv_query(          $GLOBALS[$con], $sql,array(),array( "Scrollable" => 'static' ) );
      $GLOBALS[ $stmt.'fld_cnt' ] = sqlsrv_num_fields(     $GLOBALS[$stmt]       );
      $GLOBALS[ $stmt.'fld_dta' ] = sqlsrv_field_metadata( $GLOBALS[$stmt]       );
      $GLOBALS[ $stmt.'rowCnt'  ] = sqlsrv_num_rows(       $GLOBALS[$stmt]       );
  return true; 		  
  }
}

function getSQLClose($stmt,$con) {   
  sqlsrv_free_stmt( $GLOBALS[ $stmt ] );
  sqlsrv_close(     $GLOBALS[ $con  ] );   
}

calcShed();

?>
