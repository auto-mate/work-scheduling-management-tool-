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

SELECT * FROM #WorkingAvailability;
