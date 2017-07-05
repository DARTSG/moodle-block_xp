<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * User state course store.
 *
 * @package    block_xp
 * @copyright  2017 Branch Up Pty Ltd
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_xp\local\xp;
defined('MOODLE_INTERNAL') || die();

use context_helper;
use moodle_database;
use stdClass;
use user_picture;

/**
 * User state course store.
 *
 * This is a repository of XP of each user. It also stores the level of
 * each user in the 'lvl' column, that only for ordering purposes. When
 * you change the levels_info, you must update the stored levels.
 *
 * @package    block_xp
 * @copyright  2017 Branch Up Pty Ltd
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_state_course_store {

    /** @var moodle_database The database. */
    protected $db;
    /** @var int The course ID. */
    protected $courseid;
    /** @var levels_info The levels info. */
    protected $levelsinfo;
    /** @var string The DB table. */
    protected $table = 'block_xp';

    /**
     * Constructor.
     *
     * @param moodle_database $db The DB.
     * @param levels_info $levelsinfo The levels info.
     * @param int $courseid The course ID.
     */
    public function __construct(moodle_database $db, levels_info $levelsinfo, $courseid) {
        $this->db = $db;
        $this->levelsinfo = $levelsinfo;
        $this->courseid = $courseid;
    }

    /**
     * Get a state.
     *
     * @param int $id The object ID.
     * @return state
     */
    public function get_state($id) {
        $userfields = user_picture::fields('u', null, 'userid');
        $contextfields = context_helper::get_preload_record_columns_sql('ctx');

        $sql = "SELECT u.id, x.userid, x.xp, $userfields, $contextfields
                  FROM {user} u
                  JOIN {context} ctx
                    ON ctx.instanceid = u.id
                   AND ctx.contextlevel = :contextlevel
             LEFT JOIN {{$this->table}} x
                    ON x.userid = u.id
                   AND x.courseid = :courseid
                 WHERE u.id = :userid";

        $params = [
            'contextlevel' => CONTEXT_USER,
            'courseid' => $this->courseid,
            'userid' => $id
        ];

        return $this->make_state_from_record($this->db->get_record_sql($sql, $params, MUST_EXIST));
    }

    /**
     * Return whether the entry exists.
     *
     * @param int $id The receiver.
     * @return stdClass|false
     */
    protected function exists($id) {
        $params = [];
        $params['userid'] = $id;
        $params['courseid'] = $this->courseid;
        return $this->db->get_record($this->table, $params);
    }

    /**
     * Add a certain amount of experience points.
     *
     * @param int $id The receiver.
     * @param int $amount The amount.
     */
    public function increase($id, $amount) {
        if ($record = $this->exists($id)) {
            $sql = "UPDATE {{$this->table}}
                       SET xp = xp + :xp
                     WHERE courseid = :courseid
                       AND userid = :userid";
            $params = [
                'xp' => $amount,
                'courseid' => $this->courseid,
                'userid' => $id
            ];
            $this->db->execute($sql, $params);

            // Non-atomic level update. We best guess what the XP should be, and go from there.
            $newxp = $record->xp + $amount;
            $newlevel = $this->levelsinfo->get_level_from_xp($newxp)->get_level();
            if ($record->lvl != $newlevel) {
                $this->db->set_field($this->table, 'lvl', $newlevel, ['courseid' => $this->courseid, 'userid' => $id]);
            }
        } else {
            $this->insert($id, $amount);
        }
    }

    /**
     * Insert the entry in the database.
     *
     * @param int $id The receiver.
     * @param int $amount The amount.
     */
    protected function insert($id, $amount) {
        $record = new stdClass();
        $record->courseid = $this->courseid;
        $record->userid = $id;
        $record->xp = $amount;
        $record->lvl = $this->levelsinfo->get_level_from_xp($amount)->get_level();
        $this->db->insert_record($this->table, $record);
    }

    /**
     * Make a user_state from the record.
     *
     * @param stdClass $record The row.
     * @param string $useridfield The user ID field.
     * @return user_state
     */
    public function make_state_from_record(stdClass $record, $useridfield = 'userid') {
        $user = user_picture::unalias($record, null, $useridfield);
        context_helper::preload_from_record($record);
        $xp = !empty($record->xp) ? $record->xp : 0;
        return new user_state($user, $xp, $this->levelsinfo);
    }

    /**
     * Recalculate all the levels.
     *
     * Remember, these values are used for ordering only.
     *
     * @return void
     */
    public function recalculate_levels() {
        $rows = $this->db->get_recordset($this->table, ['courseid' => $this->courseid]);
        foreach ($rows as $row) {
            $level = $this->levelsinfo->get_level_from_xp($row->xp)->get_level();
            if ($level != $row->lvl) {
                $row->lvl = $level;
                $this->db->update_record($this->table, $row);
            }
        }
        $rows->close();
    }

    /**
     * Reset all experience points.
     *
     * @return void
     */
    public function reset() {
        $this->db->delete_records($this->table, ['courseid' => $this->courseid]);
    }

    /**
     * Reset all experience for users in a group.
     *
     * @param int $groupid The group ID.
     * @return void
     */
    public function reset_by_group($groupid) {
        $sql = "DELETE
                  FROM {{$this->table}}
                 WHERE courseid = :courseid
                   AND userid IN
               (SELECT gm.userid
                  FROM {groups_members} gm
                 WHERE gm.groupid = :groupid)";

        $params = [
            'courseid' => $this->courseid,
            'groupid' => $groupid
        ];

        $this->db->execute($sql, $params);
    }

    /**
     * Set the amount of experience points.
     *
     * @param int $id The receiver.
     * @param int $amount The amount.
     */
    public function set($id, $amount) {
        if ($this->exists($id)) {

            $sql = "UPDATE {{$this->table}}
                       SET xp = :xp,
                           lvl = :lvl
                     WHERE courseid = :courseid
                       AND userid = :userid";
            $params = [
                'xp' => $amount,
                'courseid' => $this->courseid,
                'userid' => $id,
                'lvl' => $this->levelsinfo->get_level_from_xp($amount)->get_level()
            ];
            $this->db->execute($sql, $params);
        } else {
            $this->insert($id, $amount);
        }
    }

}