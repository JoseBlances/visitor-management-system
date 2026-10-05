// Shared campus overlay and map building blocks for the admin and security Leaflet maps.
window.CampusMap = (function () {
    "use strict";

    const OFFICE_COLOR = "#0755b5";
    const GATE_COLOR = "#159969";
    /** The closest zoom on every staff map, about 4 cm of ground per screen pixel. */
    const MAX_ZOOM = 22;
    /** OpenStreetMap's own images stop at this zoom; closer zooms enlarge them. */
    const RASTER_NATIVE_ZOOM = 19;
    /** Office and gate names stay on the map from this zoom in. */
    const LABEL_ZOOM = 18;
    /**
     * Free vector map with no key (the visitor app uses the same one). It is drawn from
     * shapes rather than pictures, so it stays sharp at every zoom.
     */
    const VECTOR_STYLE_URL = "https://tiles.openfreemap.org/styles/liberty";
    const VECTOR_ATTRIBUTION = '<a href="https://openfreemap.org" target="_blank" rel="noopener">OpenFreeMap</a> '
        + '&copy; <a href="https://www.openmaptiles.org/" target="_blank" rel="noopener">OpenMapTiles</a> '
        + 'Data from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';
    const RASTER_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';
    /** A vector style that has not loaded by then is replaced by OpenStreetMap images. */
    const VECTOR_STYLE_TIMEOUT_MS = 15000;
    // Both icons stay in the button; CSS shows one. Replacing them during a click would
    // detach the clicked element, and Leaflet would then treat the click as a map click.
    const EXPAND_ICONS = '<svg class="campus-expand-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/></svg>'
        + '<svg class="campus-collapse-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4v5H4M20 9h-5V4M15 20v-5h5M4 15h5v5"/></svg>';

    async function load() {
        try {
            const response = await fetch("campus_map.php", { credentials: "same-origin" });
            const data = await response.json();
            return data && data.success ? data.campus : null;
        } catch (error) {
            return null;
        }
    }

    /** Options every staff map shares: zoom down to MAX_ZOOM in half steps. */
    function mapOptions(extra) {
        return Object.assign({ maxZoom: MAX_ZOOM, zoomSnap: 0.5, zoomDelta: 0.5 }, extra || {});
    }

    function webglAvailable() {
        try {
            const canvas = document.createElement("canvas");
            const gl = canvas.getContext("webgl2") || canvas.getContext("webgl");
            if (!gl) {
                return false;
            }
            const loseContext = gl.getExtension("WEBGL_lose_context");
            if (loseContext) {
                loseContext.loseContext();
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    function addRasterLayer(map) {
        return L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxNativeZoom: RASTER_NATIVE_ZOOM,
            maxZoom: MAX_ZOOM,
            attribution: RASTER_ATTRIBUTION,
        }).addTo(map);
    }

    /**
     * The base map. Browsers with WebGL get the sharp vector map; others, or when it cannot
     * load (offline, blocked), get OpenStreetMap images, enlarged past zoom 19.
     */
    function addBaseLayer(map) {
        if (typeof window.maplibregl === "undefined" || typeof L.maplibreGL !== "function" || !webglAvailable()) {
            return addRasterLayer(map);
        }
        let vector = null;
        let switched = false;
        let styleReady = false;
        function fallBack() {
            if (switched) {
                return;
            }
            switched = true;
            if (vector && map.hasLayer(vector)) {
                map.removeLayer(vector);
            }
            addRasterLayer(map);
        }
        try {
            vector = L.maplibreGL({
                style: VECTOR_STYLE_URL,
                attributionControl: { customAttribution: VECTOR_ATTRIBUTION },
            }).addTo(map);
            const gl = vector.getMaplibreMap();
            gl.once("styledata", function () {
                styleReady = true;
            });
            gl.on("error", function () {
                if (!styleReady) {
                    fallBack();
                }
            });
            window.setTimeout(function () {
                if (!styleReady) {
                    fallBack();
                }
            }, VECTOR_STYLE_TIMEOUT_MS);
        } catch (error) {
            fallBack();
        }
        return vector;
    }

    /** A scale bar in meters and feet, for judging distances such as the 3 m arrival rule. */
    function addScale(map) {
        L.control.scale({ position: "bottomright", metric: true, imperial: true, maxWidth: 140 }).addTo(map);
    }

    /** Shows office and gate names on the map once zoomed in to LABEL_ZOOM or closer. */
    function enableLabels(map) {
        const update = function () {
            map.getContainer().classList.toggle("show-campus-labels", map.getZoom() >= LABEL_ZOOM);
        };
        map.on("zoomend", update);
        update();
    }

    /**
     * Adds a button under the zoom buttons that expands `target` (the map, or the map with
     * its panel) to fill the browser window; the button or Esc returns it. After the map has
     * resized, options.onChange(expanded) runs.
     */
    function addExpandControl(map, target, options) {
        const settings = options || {};
        let expanded = false;
        let button = null;

        function paint() {
            if (!button) {
                return;
            }
            const label = expanded ? "Exit full view (Esc)" : "Expand map to full view";
            button.classList.toggle("is-expanded", expanded);
            button.title = label;
            button.setAttribute("aria-label", label);
            button.setAttribute("aria-pressed", expanded ? "true" : "false");
        }

        function onKeyDown(event) {
            if (event.key === "Escape") {
                toggle(false);
            }
        }

        function toggle(force) {
            const next = typeof force === "boolean" ? force : !expanded;
            if (next === expanded) {
                return;
            }
            expanded = next;
            target.classList.toggle("is-map-expanded", expanded);
            document.documentElement.classList.toggle("has-expanded-map", expanded);
            if (expanded) {
                document.addEventListener("keydown", onKeyDown);
            } else {
                document.removeEventListener("keydown", onKeyDown);
            }
            paint();
            // Let the new layout settle, then tell Leaflet its size changed.
            window.requestAnimationFrame(function () {
                map.invalidateSize({ pan: false });
                if (settings.onChange) {
                    settings.onChange(expanded);
                }
            });
        }

        const ExpandControl = L.Control.extend({
            options: { position: settings.position || "topright" },
            onAdd: function () {
                const bar = L.DomUtil.create("div", "leaflet-bar campus-expand-control");
                button = L.DomUtil.create("a", "", bar);
                button.href = "#";
                button.setAttribute("role", "button");
                button.innerHTML = EXPAND_ICONS;
                L.DomEvent.disableClickPropagation(bar);
                L.DomEvent.on(button, "click", function (event) {
                    // Never let this click reach the map, where it could place a pin.
                    L.DomEvent.stop(event);
                    toggle();
                });
                paint();
                return bar;
            },
        });
        map.addControl(new ExpandControl());
        return {
            toggle: toggle,
            isExpanded: function () {
                return expanded;
            },
        };
    }

    function boundaryBounds(campus) {
        if (!campus || !Array.isArray(campus.boundary) || campus.boundary.length < 3) {
            return null;
        }
        return L.latLngBounds(campus.boundary);
    }

    // Keeps the map on the ISATU campus: panning stops at the campus edge (plus a small margin)
    // and zooming out stops once the whole campus is in view.
    function lockToCampus(map, campus, fit) {
        const bounds = boundaryBounds(campus);
        map.invalidateSize();
        // A hidden map has no size, so its zoom limits cannot be worked out yet.
        if (!bounds || map.getSize().x === 0) {
            return false;
        }
        const padded = bounds.pad(0.2);
        map.setMaxBounds(padded);
        map.options.maxBoundsViscosity = 1;
        // getBoundsZoom never answers below the current limit, so clear it first; otherwise
        // a map that was in full view could not zoom back out after shrinking.
        map.options.minZoom = undefined;
        map.setMinZoom(map.getBoundsZoom(padded));
        if (fit !== false) {
            map.fitBounds(bounds, { padding: [20, 20] });
        }
        return true;
    }

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (character) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[character];
        });
    }

    /** An office pin; with a label, its name shows beside it when zoomed in. */
    function officeIcon(label) {
        return L.divIcon({
            className: "campus-office-pin",
            html: "<span></span>" + (label ? '<b class="campus-pin-label">' + escapeHtml(label) + "</b>" : ""),
            iconSize: [18, 18],
            iconAnchor: [9, 9],
        });
    }

    function gateIcon(label) {
        return L.divIcon({
            className: "campus-gate-pin",
            html: "<span></span>" + (label ? '<b class="campus-pin-label is-gate">' + escapeHtml(label) + "</b>" : ""),
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });
    }

    function drawOverlay(layer, campus) {
        layer.clearLayers();
        if (!campus) {
            return;
        }
        if (Array.isArray(campus.boundary) && campus.boundary.length >= 3) {
            L.polygon(campus.boundary, {
                color: OFFICE_COLOR,
                weight: 2,
                dashArray: "6 6",
                fillColor: OFFICE_COLOR,
                fillOpacity: 0.03,
                interactive: false,
            }).addTo(layer);
        }
        // Names show on hover; once zoomed in (enableLabels) they stay on the map instead.
        (campus.gates || []).forEach(function (gate) {
            L.marker([gate.latitude, gate.longitude], { icon: gateIcon(gate.name), keyboard: false })
                .bindTooltip(gate.name, { className: "campus-overlay-tip" })
                .addTo(layer);
        });
        (campus.offices || []).forEach(function (office) {
            L.marker([office.latitude, office.longitude], { icon: officeIcon(office.label), keyboard: false })
                .bindTooltip(office.label, { className: "campus-overlay-tip" })
                .addTo(layer);
        });
    }

    return {
        load: load,
        mapOptions: mapOptions,
        addBaseLayer: addBaseLayer,
        addScale: addScale,
        enableLabels: enableLabels,
        addExpandControl: addExpandControl,
        boundaryBounds: boundaryBounds,
        lockToCampus: lockToCampus,
        drawOverlay: drawOverlay,
        officeIcon: officeIcon,
        OFFICE_COLOR: OFFICE_COLOR,
        GATE_COLOR: GATE_COLOR,
        MAX_ZOOM: MAX_ZOOM,
    };
})();
