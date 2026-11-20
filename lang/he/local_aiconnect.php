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
 * Language strings for local_aiconnect (Hebrew).
 *
 * Strings that are not here fall back to English.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aiactions_autonomy'] = 'מעכשיו לאפשר ל-{$a->client} להריץ את {$a->tool} בלי לשאול אותי';
$string['aiactions_cannot'] = 'כבר אי אפשר להחליט על הפעולה הזו (כבר הוחלט עליה או שתוקפה פג).';
$string['aiactions_client'] = 'לקוח AI';
$string['aiactions_confirm'] = 'לאשר ולהריץ';
$string['aiactions_created'] = 'התבקש';
$string['aiactions_done'] = 'אושר. הפעולה בוצעה פעם אחת.';
$string['aiactions_event'] = 'אירוע';
$string['aiactions_expires'] = 'תוקף עד';
$string['aiactions_failed'] = 'אושר, אבל הפעולה נכשלה. הפרטים למטה.';
$string['aiactions_grant_remove'] = 'להסיר';
$string['aiactions_grant_removed'] = 'ההרשאה הוסרה. בפעם הבאה הסוכן יבקש אישור שוב.';
$string['aiactions_grants'] = 'סוכנים שהרשית להם לפעול בלי לשאול';
$string['aiactions_heading'] = 'פעולות AI';
$string['aiactions_intro'] = 'סוכן AI שמחובר לחשבון שלך ביקש לבצע פעולה שמשנה או מוחקת נתונים. שום דבר לא קורה עד שתאשרו כאן.';
$string['aiactions_log'] = 'היסטוריה';
$string['aiactions_none'] = 'אין פעולות שמחכות לך.';
$string['aiactions_notfound'] = 'הפעולה לא נמצאה, או שהיא שייכת למשתמש אחר.';
$string['aiactions_post_only'] = 'אפשר להחליט רק בכפתורים שבדף הזה.';
$string['aiactions_recent'] = 'החלטות אחרונות';
$string['aiactions_reject'] = 'לדחות';
$string['aiactions_rejected'] = 'נדחה. שום דבר לא בוצע.';
$string['aiactions_risk'] = 'סוג';
$string['aiactions_risk_destructive'] = 'משנה או מוחק נתונים';
$string['aiactions_risk_financial'] = 'כרוך בכסף';
$string['aiactions_status'] = 'מצב';
$string['aiactions_status_executed'] = 'בוצע';
$string['aiactions_status_executing'] = 'רץ עכשיו';
$string['aiactions_status_expired'] = 'פג תוקף';
$string['aiactions_status_failed'] = 'נכשל';
$string['aiactions_status_pending'] = 'מחכה לך';
$string['aiactions_status_rejected'] = 'נדחה';
$string['aiactions_summary'] = 'מה הסוכן ביקש';
$string['aiactions_technical'] = 'פרטים טכניים';
$string['aiactions_tool'] = 'כלי';
$string['aiactions_waiting'] = 'מחכות להחלטה שלך';
$string['aiactions_waiting_count'] = '{$a} פעולות AI מחכות לאישור שלך';
$string['aiactions_what'] = 'מה יקרה';
$string['confirm_generic'] = 'ביצוע הפעולה "{$a}"';
$string['confirm_user_message'] = '{$a} — עדיין לא בוצע כלום. פתחו את הקישור, בדקו את הפרטים ואשרו או דחו.';
$string['confirmation_autonomy'] = 'לאפשר למשתמשים לתת לסוכן לפעול בלי לשאול';
$string['confirmation_autonomy_desc'] = 'כשהאפשרות פעילה, משתמש יכול לסמן בדף פעולות AI "מעכשיו לאפשר לסוכן הזה להריץ את הכלי הזה בלי לשאול". כבו אותה כדי לדרוש אישור לכל קריאה.';
$string['confirmation_ttl'] = 'זמן לאישור (בשניות)';
$string['confirmation_ttl_desc'] = 'כמה זמן פעולה מחכה לאישור המשתמש לפני שתוקפה פג (60 עד 86400 שניות, ברירת מחדל 900).';
$string['eventaiactionconfirmed'] = 'פעולת AI אושרה על ידי המשתמש';
$string['eventaiactionrejected'] = 'פעולת AI נדחתה על ידי המשתמש';
$string['eventaiactionrequested'] = 'פעולת AI מחכה לאישור';
$string['pluginname'] = 'AI Connect';
$string['privacy:metadata:local_aiconnect_autonomy'] = 'הרשאות שמשתמש נתן ללקוח AI להריץ כלי אחד בלי לשאול.';
$string['privacy:metadata:local_aiconnect_autonomy:userid'] = 'המשתמש שנתן את ההרשאה.';
$string['privacy:metadata:local_aiconnect_conflog'] = 'היסטוריית שלבי האישור (בקשה, אישור, דחייה, ביצוע).';
$string['privacy:metadata:local_aiconnect_conflog:actorid'] = 'המשתמש שגרם לאירוע.';
$string['privacy:metadata:local_aiconnect_conflog:event'] = 'מה קרה.';
$string['privacy:metadata:local_aiconnect_conflog:ip'] = 'כתובת ה-IP של הבקשה.';
$string['privacy:metadata:local_aiconnect_conflog:userid'] = 'המשתמש שהפעולה שייכת לו.';
$string['privacy:metadata:local_aiconnect_pending'] = 'פעולות שסוכן AI ביקש ושצריכות את אישור המשתמש.';
$string['privacy:metadata:local_aiconnect_pending:argsjson'] = 'פרטי הפעולה שהתבקשה.';
$string['privacy:metadata:local_aiconnect_pending:resultjson'] = 'תוצאת הפעולה אחרי שבוצעה.';
$string['privacy:metadata:local_aiconnect_pending:status'] = 'האם הפעולה מחכה, בוצעה, נדחתה או פג תוקפה.';
$string['privacy:metadata:local_aiconnect_pending:summary'] = 'המשפט שמוצג למשתמש.';
$string['privacy:metadata:local_aiconnect_pending:timecreated'] = 'מתי זה התבקש.';
$string['privacy:metadata:local_aiconnect_pending:timedecided'] = 'מתי המשתמש החליט.';
$string['privacy:metadata:local_aiconnect_pending:tool'] = 'הכלי שהסוכן רצה להריץ.';
$string['privacy:metadata:local_aiconnect_pending:userid'] = 'המשתמש שצריך לאשר.';
