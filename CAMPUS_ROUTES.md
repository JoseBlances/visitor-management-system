# Campus pins from GPS and walking routes

How an administrator places departments and gates by standing at them, and records the
walking routes visitors should take, in the admin dashboard's **Campus Map**. Database
change: `phone_tracker/campus_routes_migration.sql` (import after
`visitor_arrival_migration.sql`; safe to run again; a Railway deploy applies it by itself).

## Pin at my location

Guessing a building on the map is often off by 10–30 m. Standing at the spot is better.

- **Offices tab:** choose the department, stand at its entrance, and press
  **Pin at my location**. The page collects GPS readings and averages the accurate ones
  (±8 m or better). It places the pin after 5 of those, or press **Use this location**
  to take the best so far. Then press **Save campus map**.
- **Gates tab:** type the gate's name, stand at the gate, and press **Add gate at my location**.
- Each list shows how a pin was placed: "GPS ±4 m" or "Placed on map". Dragging a pin
  turns it back into "Placed on map".

## Routes

A route is the line a visitor should walk from a start (a gate, or the guard house) to one
department: through doors and around buildings, never through a wall. A department can
have several routes, for example one from each gate.

**Record by walking (phone):**

1. In the **Routes** tab, choose the destination department, stand at the start, and press
   **Record by walking**. On a phone the map and controls fill the screen.
2. Walk the way a visitor should. The line follows you. Readings worse than ±20 m and
   sudden GPS jumps are skipped. **Pause** while waiting at a door; **Undo last 10 m**
   after a wrong turn.
3. At the department's entrance, press **I've arrived**.
4. **Review:** the cleaned line appears over the raw GPS track (dashed), with the distance,
   walking time, and any warnings. Name the start (gates are suggested). For a walked route
   you can also move the department's pin to where you stopped. Press **Save route**.

**Draw on the map (laptop, or to correct a route):** click along the walkway from the start
to the department. Drag a point to move it, click a point to remove it, and click the line
to add a point. **Reverse direction** swaps the start and the end. From the review,
**Adjust points** opens a walked route for corrections.

The list groups routes by department. Use **Show** to zoom to a route, plus **Edit** and
**Delete**. The coverage line at the top shows which departments still need a route.

**Keep the screen on while recording.** Phones stop sharing a web page's location when the
screen sleeps. The page asks the browser to keep the screen on, and it saves the unfinished
route every few seconds. After a reload, **Continue** brings the route back, paused.

**Review warnings:**

- the route ends more than 15 m from the department's pin;
- it starts more than 40 m from a gate;
- the GPS was weak, averaging worse than ±10 m;
- points lie more than 40 m outside the boundary. A route like this cannot be saved.

**Server limits:**

- 2–2000 points;
- every point inside the boundary or within 40 m of it;
- no gap over 500 m between points;
- 2 m to 5 km long;
- 20 routes per department.

## GPS needs HTTPS

Browsers share the location only with HTTPS pages or `localhost`.

- **Railway (HTTPS):** works on any phone.
- **On the laptop:** use **Draw on the map**. To simulate walking, open Chrome DevTools →
  ⋮ → More tools → **Sensors** → Location.
- **Phone over local Wi-Fi** (`http://192.168.x.x`): Chrome blocks GPS there. For testing
  only, open `chrome://flags/#unsafely-treat-insecure-origin-as-secure` on the phone, add
  `http://<laptop-ip>`, and relaunch Chrome.

## Data

- **`campus_routes`:** one row per route.
  - `office_code`, `name`, `start_label`
  - `method` (`walked` or `drawn`)
  - `points_json`: `[[lat, lng], …]`, ordered from the start to the department
  - `distance_meters`, `duration_seconds`, `average_accuracy_meters`
  - who saved it, and when

  Deleting a department deletes its routes.
- **`campus_places`:** gains `placed_by` (`map` or `gps`) and `accuracy_meters`.
- **Activity log entries:** "Updated the campus map", "Added a walking route",
  "Edited a walking route", "Removed a walking route".
- **Permissions:** administrators can add, edit, and delete routes. Security can read them
  through `campus_routes.php`.

## For the visitor app (not built yet)

The app still draws a straight pointer to the office (`VISITOR_NAVIGATION.md`), which can
lead into a wall. When the app is updated:

1. Add the routes for the visitor's stops to `phone_tracker/api/v1/campus_map.php`, using
   `campus_routes_load()`.
2. Pick the destination office's route whose line passes nearest to the visitor.
3. Guide along that line from the nearest point on it. Voice prompts go at the turns
   (where the direction changes by about 30° or more), plus "go back to the path" when the
   visitor is more than about 15 m away from the line.
4. Keep the straight pointer for departments that have no route yet.

## Files

| File | Purpose |
|---|---|
| `phone_tracker/campus_gps.js` | GPS sampling for pins, the route recorder, path math |
| `phone_tracker/campus_setup.js` | Campus Map editor: boundary, gates, office pins, GPS pins |
| `phone_tracker/campus_routes_editor.js` | Routes tab: record, draw, review, edit, list |
| `phone_tracker/campus_routes.php` | List routes (GET) |
| `phone_tracker/admin_save_campus_route.php` | Add or edit a route (POST, administrators) |
| `phone_tracker/admin_delete_campus_route.php` | Delete a route (POST, administrators) |
| `phone_tracker/campus_map_service.php` | Loading pins and routes; boundary math |
| `phone_tracker/campus_routes_migration.sql` | Routes table and pin placement columns |
