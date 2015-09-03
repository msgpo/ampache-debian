<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2015 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, 
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

class AmpacheTmdb {

    public $name           = 'Tmdb';
    public $categories     = 'metadata';
    public $description    = 'Tmdb metadata integration';
    public $url            = 'https://www.themoviedb.org';
    public $version        = '000002';
    public $min_ampache    = '370009';
    public $max_ampache    = '999999';
    
    // These are internal settings used by this class, run this->load to
    // fill them out
    private $api_key;

    /**
     * Constructor
     * This function does nothing
     */
    public function __construct() {
        return true;
    }

    /**
     * install
     * This is a required plugin function
     */
    public function install() {
        
        if (Preference::exists('tmdb_api_key')) { return false; }

        Preference::insert('tmdb_api_key','Tmdb api key','','75','string','plugins');
        
        return true;
    } // install

    /**
     * uninstall
     * This is a required plugin function
     */
    public function uninstall() {
    
        Preference::delete('tmdb_api_key');
        
        return true;
    } // uninstall

    /**
     * load
     * This is a required plugin function; here it populates the prefs we 
     * need for this object.
     */
    public function load($user) {
        
        $user->set_preferences();
        $data = $user->prefs;

        if (strlen(trim($data['tmdb_api_key']))) {
            $this->api_key = trim($data['tmdb_api_key']);
        }
        else {
            debug_event($this->name,'No Tmdb api key, metadata plugin skipped','3');
            return false;
        }
        
        return true;
    } // load

    /**
     * get_metadata
     * Returns song metadata for what we're passed in.
     */
    public function get_metadata($gather_types, $media_info) {
        debug_event('tmdb', 'Getting metadata from Tmdb...', '5');

        // TVShow / Movie metadata only
        if (!in_array('tvshow', $gather_types) && !in_array('movie', $gather_types)) {
            debug_event('tmdb', 'Not a valid media type, skipped.', '5');
            return null;
        }
        
        try {
            $token = new \Tmdb\ApiToken($this->api_key);
            $client = new \Tmdb\Client($token);
            $configRepository = new \Tmdb\Repository\ConfigurationRepository($client);
            $config = $configRepository->load();
            $imageHelper = new \Tmdb\Helper\ImageHelper($config);
            
            $title = $media_info['original_name'] ?: $media_info['title'];
            
            $results = array();
            $release_info = Vainfo::parseFileName($media_info['file'], $gather_types);
            if (empty($release_info[0])) {
                debug_event('tmdb', 'Could not parse title, skipped.', '5');
                return null;
            }
            if (in_array('tvshow', $gather_types)) {
                $results['tvshow'] = trim($release_info[0]);
                $results['tvshow_season'] = $release_info[1];
                $results['tvshow_episode'] = $release_info[2];
                $results['year'] = $release_info[3];
            }
            else {
                $results['title'] = $release_info[0];
                $results['year'] = $release_info[1];
            }
            
            if (in_array('movie', $gather_types)) {
                if (!empty($results['title'])) {
                    $apires = $client->getSearchApi()->searchMovies($results['title']);
                    if (count($apires['results']) > 0) {
                        $results['tmdb_id'] = $apires['results'][0]['id'];
                        $release = $client->getMoviesApi()->getMovie($results['tmdb_id']);
                        $results['imdb_id'] = $release['imdb_id'];
                        $results['original_name'] = $release['original_title'];
                        if (!empty($release['release_date'])) {
                            $results['release_date'] = strtotime($release['release_date']);
                            $results['year'] = date("Y", $results['release_date']);  // Production year shouldn't be the release date
                        }
                        if ($release['poster_path']) {
                            $results['art'] = $imageHelper->getUrl($release['poster_path']);
                        }
                        if ($release['backdrop_path']) {
                            $results['background_art'] = $imageHelper->getUrl($release['backdrop_path']);
                        }
                        $results['genre'] = self::get_genres($release);
                    }
                }
            }
            
            if (in_array('tvshow', $gather_types)) {
                if (!empty($results['tvshow'])) {
                    $apires = $client->getSearchApi()->searchTv($results['tvshow']);
                    if (count($apires['results']) > 0) {
                        // Get first match
                        $results['tmdb_tvshow_id'] = $apires['results'][0]['id'];
                        $release = $client->getTvApi()->getTvshow($results['tmdb_tvshow_id']);
                        $results['tvshow'] = $release['original_name'];
                        if (!empty($release['first_air_date'])) {
                            $results['tvshow_year'] = date("Y", strtotime($release['first_air_date']));
                        }
                        if ($release['poster_path']) {
                            $results['tvshow_art'] = $imageHelper->getUrl($release['poster_path']);
                        }
                        if ($release['backdrop_path']) {
                            $results['tvshow_background_art'] = $imageHelper->getUrl($release['backdrop_path']);
                        }
                        $results['genre'] = self::get_genres($release);
                        
                        if ($results['tvshow_season']) {
                            $release = $client->getTvSeasonApi()->getSeason($results['tmdb_tvshow_id'], $results['tvshow_season']);
                            if ($release['id']) {
                                if ($release['poster_path']) {
                                    $results['tvshow_season_art'] = $imageHelper->getUrl($release['poster_path']);
                                }
                                if ($media_info['tvshow_episode']) {
                                    $release = $client->getTvEpisodeApi()->getEpisode($results['tmdb_tvshow_id'], $results['tvshow_season'], $results['tvshow_episode']);
                                    if ($release['id']) {
                                        $results['tmdb_id'] = $release['id'];
                                        $results['tvshow_season'] = $release['season_number'];
                                        $results['tvshow_episode'] = $release['episode_number'];
                                        $results['original_name'] = $release['name'];
                                        if (!empty($release['air_date'])) {
                                            $results['release_date'] = strtotime($release['release_date']);
                                            $results['year'] = date("Y", $results['release_date']);
                                        }
                                        $results['description'] = $release['overview'];
                                        if ($release['still_path']) {
                                            $results['art'] = $imageHelper->getUrl($release['still_path']);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            debug_event('tmdb', 'Error getting metadata: ' . $e->getMessage(), '1');
        }
        
        return $results;
    } // get_metadata
    
    public function gather_arts($type, $options = array(), $limit = 5)
    {
        debug_event('Tmdb', 'gather_arts for type `' . $type . '`', 5);
        return Art::gather_metadata_plugin($this, $type, $options);
    }
    
    private static function get_genres($release)
    {
        $genres = array();
        if (is_array($release['genres'])) {
            foreach ($release['genres'] as $genre) {
                if (!empty($genre['name'])) {
                    $genres[] = $genre['name'];
                }
            }
        }
        return $genres;
    }
    private function getResultByTitle($results, $title, $gather_type, $year)
    {
        $titles = array();
    
        foreach ($results as $index)
        {
            if (in_array('movie', $gather_type)) {
                if ((strtoupper($title) == strtoupper($index['title'])) && (strtoupper($index['original_title']) == strtoupper($title))) {
                    $titles[] = $index;
                }
            }
            else {
                if ((strtoupper($title) == strtoupper($index['name'])) && (strtoupper($index['original_name']) == strtoupper($title))) {
                    $titles[] = $index;
                }
            }
        }
        if ((count($titles) > 1) && ($year != null)) {
            foreach ($titles as $index)
            {
                $y = in_array('movie', $gather_type) ? date("Y",strtotime($index['release_date'])) : date("Y",strtotime($index['first_air_date']));
                if ($year == $y) {
                    return $index;
                }
            }
        }
        return count($titles) > 0 ? $titles[0] : $results[0];
    }
    
} // end AmpacheTmdb
?>
