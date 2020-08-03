<?php

namespace Waddle\Parsers;

use DateTime;
use Exception;
use SimpleXMLElement;
use Waddle\Activity;
use Waddle\Lap;
use Waddle\Parser;
use Waddle\TrackPoint;

class GPXParser extends Parser {
    /**
     * Parse the GPX file
     * @param string $pathname
     * @return Activity
     * @throws Exception
     */
    public function parse($pathname) {
        // Check that the file exists
        $this->checkForFile($pathname);

        // Create a new activity instance
        $activity = new Activity();

        // Load the XML in the GPX file
        $data = simplexml_load_file($pathname);

        if (!isset($data->trk)) {
            throw new Exception('Unable to find valid activity in file contents');
        }

        // Parse the first activity
        $activityNode = $data->trk;
        $activity->setStartTime(new DateTime((string) $activityNode->trkseg[0]->trkpt[0]->time));
        $activity->setType((string) $activityNode->name[0]);

        // Now parse the trksegs (Track Segments, I assume)
        // There should only be 1 trkseg, but they are stored in an array just in case this ever changes
        foreach ($activityNode->trkseg as $lapNode) {
            if (!$lapNode->trkpt) {
                // In some cases there can be an empty lap node
                continue;
            }

            $activity->addLap($this->parseLap($lapNode));
        }

        // Finally return the activity object
        return $activity;
    }

    /**
     * Parse a GPX file with multiple tracks
     *
     * @param      string     $pathname  The pathname
     *
     * @throws     Exception  invalid data
     *
     * @return     array      Array of Activity Objects
     */
    public function parseMultiple($pathname) {
        // TODO: still WIP!

        // Check that the file exists
        $this->checkForFile($pathname);

        // Load the XML in the GPX file
        $data = simplexml_load_file($pathname);

        if (!isset($data->{@attributes}) && !isset($data->trk) && !isset($data->wpt)) {
            throw new Exception('Unable to find valid activity in file contents');
        }

        // According to specs, a GPX file can have from 0 to infinite number of tracks
        $activities = [];
        foreach ($data->trk as $activityNode) {
            // Create a new activity instance
            $activity = new Activity();
            // Parse the activity
            $firstTrackSegment = $activityNode->trkseg;
            if ($firstTrackSegment) {
                $firstTrackPoint = $firstTrackSegment->trkpt;
                if ($firstTrackPoint) {
                    $activity->setStartTime(new DateTime((string) $firstTrackPoint->time));
                } else {
                    continue;
                }
            } else {
                continue;
            }

            $type = (string) $activityNode->type;
            if (!$type) {
                $type = (string) $activityNode->name[0];
            }
            $activity->setType($type);

            // Now parse the trksegs (Track Segments)
            foreach ($activityNode->trkseg as $lapNode) {
                if (!$lapNode->trkpt) {
                    // In some cases there can be an empty lap node
                    continue;
                }

                $activity->addLap($this->parseLap($lapNode));
            }

            $activities[] = $activity;
        }

        // Finally return all activity objects
        return $activities;
    }

    /**
     * Parse the lap XML (trkseg)
     * @param SimpleXMLElement $lapNode
     * @return Lap
     * @throws Exception
     */
    protected function parseLap($lapNode) {
        $lap = new Lap();

        // GPX files don't have the overall information, so we will have to calculate that afterwards
        $totalTime = 0;
        $maxSpeed = 0;

        /** @var TrackPoint|null $lastTrackPointNode */
        $lastTrackPointNode = null;

        // Loop through the track points
        foreach ($lapNode->trkpt as $trackPointNode) {
            $trackPoint = $lap->addTrackPoint($this->parseTrackPoint($trackPointNode, $lastTrackPointNode));

            // Add up the time
            if (!is_null($lastTrackPointNode)) {
                $totalTime += $trackPoint->getTime('U') - $lastTrackPointNode->getTime('U');
            }

            if ($trackPoint->getSpeed() > $maxSpeed) {
                $maxSpeed = $trackPoint->getSpeed();
            }

            $lastTrackPointNode = $trackPoint;
        }

        // Now, using the last track point we can get the total distance
        $lap->setTotalDistance($lastTrackPointNode->getDistance());
        $lap->setTotalTime($totalTime);
        $lap->setMaxSpeed($maxSpeed);

        return $lap;
    }

    /**
     * Parse the XML of a track point
     * @param SimpleXMLElement $trackPointNode
     * @param TrackPoint|null $previousTrackPoint
     * @return TrackPoint
     * @throws Exception
     */
    protected function parseTrackPoint($trackPointNode, $previousTrackPoint) {
        $point = new TrackPoint();
        $point->setTime(new DateTime((string) $trackPointNode->time));
        $point->setPosition(['lat' => (float) $trackPointNode['lat'], 'lon' => (float) $trackPointNode['lon']]);
        $point->setAltitude((float) $trackPointNode->ele);
        $extensions = $trackPointNode->extensions;
        if ($extensions) {
            $point->setHeartRate((float) $extensions->children('gpxtpx', true)->TrackPointExtension->hr);
        }
        // GPX files don't store the distance traveled, that will have to be calculated from lat/lon
        $distance = 0;
        $speed = 0;

        if (!is_null($previousTrackPoint)) {
            // Distance
            $distanceTravelled = $this->calculateDistanceBetweenLatLon(
                $previousTrackPoint->getPosition('lat'),
                $point->getPosition('lat'),
                $previousTrackPoint->getPosition('lon'),
                $point->getPosition('lon')
            );

            $distance = $previousTrackPoint->getDistance() + $distanceTravelled;

            // Speed = Distance / Time
            // Each track point should be recorded 1 second after the last, but let's just confirm that
            $timeDiff = $point->getTime('U') - $previousTrackPoint->getTime('U');

            if ($timeDiff != 0) {
                $speed = $distanceTravelled / $timeDiff; # Meters per Second
            }
        }

        $point->setDistance($distance);
        $point->setSpeed($speed);

        return $point;
    }
}
