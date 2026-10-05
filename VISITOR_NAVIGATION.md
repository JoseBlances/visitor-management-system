# Visitor campus map, walking directions, and arrivals

What the visitor sees in the Android app after Security checks them in, how directions and
voice prompts work, how an arrival is confirmed, and what Security and the office see.
Security's side of the visit is in `LIVE_MONITORING.md`. Database change:
`phone_tracker/visitor_arrival_migration.sql` (import after `live_monitoring_migration.sql`;
safe to run again).

## The screen

The **Campus visit** screen opens by itself when the pass is scanned at the gate.

- **Map:** OpenStreetMap through MapLibre, with the campus outline (the outside is dimmed),
  the office pin with its name, gate pins, other stops of a multi-office visit (numbered),
  and the visitor's blue arrow. While following, the map turns with the visitor (heading-up)
  and keeps them in the lower part of the screen. Dragging the map stops following;
  **My position** resumes it, **Whole route** shows the visitor and the office north-up,
  and the compass button turns the map north again. There is no circle around the arrow:
  GPS accuracy is shown as text ("GPS ±4 m") so it is never mistaken for the arrival area.
- **Dotted line:** points straight from the visitor to the office. It is a pointer, not a
  walking route (there is no walkway data), and the app says so. The visitor's walked trail
  is never shown here; it stays with Security.
- **Banner:** an arrow that points at the office from where the phone faces, the distance
  (to the meter under 20 m), how accurate GPS is right now, which side ("slightly to your
  right"), and the office's location from the Department Directory.
- **Panel (pull up):** walking time and distance, **Show pass**, voice on/off, **Guide me out**,
  check-in time, time on campus, the booked slot, office stops, settings, and location
  sharing.

## Arrival: only what GPS can actually confirm

Phone GPS is accurate to about ±3–10 m outdoors and ±10–50 m indoors, and the position
drifts a few meters even when the phone is still. The app therefore never claims more than
the reading supports:

| State | When | What the visitor sees and hears |
|---|---|---|
| **Arrived (GPS)** | Within the arrival distance (3 m by default) of the office pin, from a reading accurate to 8 m or better, for 3 readings in a row (about 3 seconds) | "You have arrived at IT Department. It's at CCI building, room 201." |
| **Very close** | The pin is inside the reading's own uncertainty (accuracy 20 m or better), so GPS can't confirm the last few meters | "You're very close to IT Department… Tap I'm here when you reach it." The banner says "within about 6 m" and offers **I'm here** |
| **Arrived (visitor)** | The visitor taps **I'm here** (shown when very close, within 30 m, with weak GPS, or when the office has no pin) | "Arrival confirmed at IT Department." |

- The arrival distance is the server setting `arrival_distance_meters` in
  `mobile_api_settings` (1–30 m, default 3). Changing it needs no app update. The accuracy
  limit is `ARRIVAL_MAX_ACCURACY_METERS` in `arrival_service.php`.
- A weak reading can never produce an arrival: with GPS at ±30 m, the app shows the distance
  with its accuracy and stays quiet. (Version 0.3.0 used the accuracy as the arrival radius,
  up to 30 m; that is removed.)
- After arriving, the app stays quiet for that office, so indoor drift never restarts
  directions during a meeting. Reopening the app shows the arrival already on record.
- Place office pins at the **entrance** visitors walk to, not the middle of a building.
  Inside buildings GPS usually can't confirm 3 m, so visitors tap **I'm here**.

## Voice directions

Spoken with the phone's text-to-speech in English, as navigation audio (music is lowered,
not stopped). Distances are spoken only from readings accurate to 20 m or better.

| When | Prompt |
|---|---|
| Directions start | "Heading to IT Department. It's 120 meters away, slightly to your left." |
| 200 m, 100 m, 50 m, 10 m | "IT Department is 50 meters away, to your right." |
| 25 m | "IT Department is just ahead." |
| Walking the wrong way (25 m farther than the closest point) | "You're moving away from IT Department. It's 150 meters away, behind you." |
| Very close / arrived | See the table above |
| Next stop (multi-office visit) | "Next stop: Dean's Office. It's 80 meters away, to your right." |
| All stops done, or **Guide me out** | "All your office stops are done. Head to Main Gate. It's 140 meters away, …" |
| At the gate | "You have arrived at Main Gate. Show your pass to the guard to check out." |
| Stepped outside the campus | "You are outside the campus. Your visit ends automatically if you stay outside for 5 minutes." |
| Back inside | "Welcome back to the campus." |
| Office has no pin | "IT Department isn't on the campus map yet. Please ask a guard for directions." |

Each prompt is spoken once and never within 6 seconds of another. Leaving the campus uses
the server's exact rule (`campus_map.php` `exit_policy`): past the 25 m allowance, from
readings accurate to 50 m. **Which side** comes from the walking direction while moving
(over 0.8 m/s), otherwise from the compass, which reads the top edge of a phone held flat or
the back of a phone held upright and is corrected to true north. Distances are in meters
(to the meter under 20 m) or feet (to the foot under 60 ft). All rules are in
`navigation/Guidance.kt` and unit tested (`GuidanceEngineTest`).

## What Security and the office see

The app reports each confirmed arrival to `api/v1/arrival.php` (retried for about 10 minutes
without a connection). The server checks a GPS arrival against the same rule, keeps only the
first arrival per stop, and in one transaction:

1. stores `arrived_at`, `arrival_method` (`gps` or `visitor`), and the distance and accuracy
   on the stop's appointment;
2. notifies the office's staff: "Juan Dela Cruz has arrived at IT Department (confirmed by
   GPS (±4 m))."; and
3. writes "Arrived at an office" to the admin activity log (Gate & visits).

- **Security dashboard:** a green **Arrived** badge in the visitor list, "Arrived: IT Department
  at 2:03 PM, confirmed by GPS (±4 m)" on the visitor card, and an arrival pin on the map. A
  walked path that only passed within 30 m of an office shows "Passed near … Arrival not
  confirmed" instead; it is never called an arrival.
- **Office dashboard:** the visitor's status reads **Arrived** instead of Checked in, and the
  appointment shows "Arrived at this office" with the time and how it was confirmed.

## Settings (on the phone)

Voice directions (on), distances (meters or feet), and **Keep screen on** while the map is
open (on).

## What an administrator sets up

In **Campus Map**: the boundary, a pin for each department **at its entrance**, and gates
(used to guide visitors out; without gates the app guides them back to where they were
checked in). In **User Management → Department Directory**, each department's **location**
(building, floor, room) is shown and spoken on arrival. Office accounts receive arrival
notifications, so each office needs an active Office Personnel account.

## Location sharing and privacy

- The second-by-second position that drives the map stays on the phone. Security receives
  only the background uploads described in `LIVE_MONITORING.md`, plus the arrival report.
- **Stop sharing my location** asks for confirmation, then withdraws consent and stops
  uploads; the visit and directions continue. **Share my location again** restarts sharing.
- When the visit ends, the pass screen shows a summary: check-in time, how it ended, and time
  on campus.

## Map source

`ISATU_MAP_STYLE_URL` in `visitor_app/local.properties` chooses the MapLibre style. The
default, `https://tiles.openfreemap.org/styles/liberty`, is free and needs no key. Without
a connection the map turns plain and everything else keeps working; **Retry** reloads it.
The last campus map is kept on the phone for short losses of connection.

## Files

| File | Purpose |
|---|---|
| `visitor_app/.../ui/TrackingMapScreen.kt` | The screen: banner, panel, pass, sharing, I'm here |
| `visitor_app/.../ui/CampusMapView.kt` | MapLibre map, campus layers, pins, heading-up camera |
| `visitor_app/.../navigation/Guidance.kt` | Directions, voice prompts, arrival and exit rules |
| `visitor_app/.../navigation/GeoMath.kt` | Distances, directions, the server's boundary math |
| `visitor_app/.../navigation/HeadingSensor.kt` | Compass |
| `visitor_app/.../navigation/LiveLocation.kt` | Second-by-second position for the screen |
| `visitor_app/.../navigation/VoiceGuide.kt` | Text-to-speech |
| `phone_tracker/api/v1/campus_map.php` | Boundary, gates, stops with locations and arrivals, rules |
| `phone_tracker/api/v1/arrival.php`, `phone_tracker/arrival_service.php` | Recording an arrival |
| `phone_tracker/visitor_arrival_migration.sql` | Arrival columns and the arrival distance setting |

## Testing

1. In Campus Map, place the pin of the office you will book at a spot you can stand on
   outdoors (and a gate). Make sure the office has an active Office Personnel account.
2. Book a walk-in for that office in the app and have Security scan the pass.
3. Walk toward the pin outdoors: prompts at 100, 50, 25, and 10 m, then "You have arrived"
   only once you stand on the pin (within about 3 m, with a clear signal). From farther
   away, or under a roof, you get "very close" and **I'm here** instead.
4. Check Security's Visitor Monitoring (Arrived badge and card) and the office's dashboard
   (status Arrived, notification).
5. Tap **Guide me out** and walk to the gate; at the gate, **Show pass** and scan it.
6. On another visit, step more than 25 m outside the boundary: the warning, the countdown,
   and "Welcome back" when you return.

Unit tests: `.\gradlew.bat testDebugUnitTest` (GeoMathTest, GuidanceEngineTest).
