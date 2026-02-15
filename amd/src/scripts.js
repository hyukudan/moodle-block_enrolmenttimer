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
 * Live countdown timer for the enrolment timer block.
 *
 * Uses stable data-unit attributes (language-independent) and wall-clock
 * timing to avoid drift from setInterval inaccuracies and tab throttling.
 *
 * @module     block_enrolmenttimer/scripts
 * @copyright  2014 onwards LearningWorks Ltd {@link https://learningworks.co.nz/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Aaron Leggett
 */
define(['jquery'], function($) {

    return {
        /**
         * Initialise the countdown timer.
         */
        initialise: function() {
            $(document).ready(function() {
                var activeUnits = [];
                var forceTwoDigits = false;
                var intervalId = null;
                var endTime = 0;

                var unitSeconds = {
                    'years': 31536000,
                    'months': 2592000,
                    'weeks': 604800,
                    'days': 86400,
                    'hours': 3600,
                    'minutes': 60,
                    'seconds': 1
                };

                /**
                 * Find displayed time unit elements using stable data-unit attributes.
                 */
                function getDisplayedUnits() {
                    var children = $('.block_enrolmenttimer .active .timer-wrapper').find('.timerNum[data-unit]');
                    children.each(function() {
                        activeUnits.push($(this).attr('data-unit'));
                    });
                }

                /**
                 * Calculate initial total seconds from text description spans using data-unit.
                 */
                function calculateInitialTimestamp() {
                    var timestamp = 0;
                    for (var i = 0; i < activeUnits.length; i++) {
                        var unitKey = activeUnits[i];
                        var val = parseInt(
                            $('.block_enrolmenttimer .active .text-desc [data-unit="' + unitKey + '"]').text(),
                            10
                        );
                        if (!isNaN(val) && unitSeconds[unitKey]) {
                            timestamp += val * unitSeconds[unitKey];
                        }
                    }
                    return timestamp;
                }

                /**
                 * Update a single counter element in the DOM.
                 *
                 * @param {string} unitKey The stable unit key (e.g. 'hours').
                 * @param {number} time The value to display.
                 */
                function updateMainCounter(unitKey, time) {
                    var html = '';
                    var timeStr = time.toString();
                    if (forceTwoDigits === true && timeStr.length === 1) {
                        html += '<span class="timerNumChar" data-id="0">0</span>';
                        html += '<span class="timerNumChar" data-id="1">' + timeStr + '</span>';
                    } else {
                        for (var i = 0; i < timeStr.length; i++) {
                            html += '<span class="timerNumChar" data-id="' + i + '">' +
                                timeStr.charAt(i) + '</span>';
                        }
                    }

                    $('.block_enrolmenttimer .active .timer-wrapper .timerNum[data-unit="' + unitKey + '"]')
                        .html(html);
                    $('.block_enrolmenttimer .active .text-desc [data-unit="' + unitKey + '"]')
                        .html(time);
                }

                /**
                 * Calculate remaining time from wall clock and update all displayed counters.
                 */
                function updateLiveCounter() {
                    var remaining = Math.max(0, Math.floor((endTime - Date.now()) / 1000));

                    if (remaining <= 0) {
                        if (intervalId !== null) {
                            window.clearInterval(intervalId);
                            intervalId = null;
                        }
                        for (var j = 0; j < activeUnits.length; j++) {
                            updateMainCounter(activeUnits[j], 0);
                        }
                        return;
                    }

                    var time = remaining;
                    var tokens = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'];
                    var units = [31536000, 2592000, 604800, 86400, 3600, 60, 1];

                    for (var i = 0; i < tokens.length; i++) {
                        if (activeUnits.indexOf(tokens[i]) !== -1) {
                            var count = Math.floor(time / units[i]);
                            updateMainCounter(tokens[i], count);
                            time = time - (count * units[i]);
                        }
                    }
                }

                if ($('.block_enrolmenttimer .active').length > 0) {
                    if ($('.block_enrolmenttimer .timer-wrapper[data-id=force2]').length > 0) {
                        forceTwoDigits = true;
                    }

                    getDisplayedUnits();
                    var initialSeconds = calculateInitialTimestamp();
                    endTime = Date.now() + (initialSeconds * 1000);

                    intervalId = window.setInterval(function() {
                        updateLiveCounter();
                    }, 1000);

                    // Handle tab visibility changes to keep timer accurate.
                    if (typeof document.addEventListener === 'function') {
                        document.addEventListener('visibilitychange', function() {
                            if (!document.hidden && intervalId === null && endTime > Date.now()) {
                                intervalId = window.setInterval(function() {
                                    updateLiveCounter();
                                }, 1000);
                                updateLiveCounter();
                            }
                        });
                    }
                }
            });
        }
    };
});
