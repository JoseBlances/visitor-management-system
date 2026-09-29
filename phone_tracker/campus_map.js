// Shared campus overlay for the admin and security Leaflet maps.
window.CampusMap = (function () {
    "use strict";

    const OFFICE_COLOR = "#0755b5";
    const GATE_COLOR = "#159969";

    async function load() {
        try {
            const response = await fetch("campus_map.php", { credentials: "same-origin" });
            const data = await response.json();
            return data && data.success ? data.campus : null;
        } catch (error) {
            return null;
        }
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
        map.setMinZoom(map.getBoundsZoom(padded));
        if (fit !== false) {
            map.fitBounds(bounds, { padding: [20, 20] });
        }
        return true;
    }

    function officeIcon() {
        return L.divIcon({
            className: "campus-office-pin",
            html: "<span></span>",
            iconSize: [18, 18],
            iconAnchor: [9, 9],
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
        (campus.gates || []).forEach(function (gate) {
            L.circleMarker([gate.latitude, gate.longitude], {
                radius: 7,
                color: "#ffffff",
                weight: 2,
                fillColor: GATE_COLOR,
                fillOpacity: 1,
            }).bindTooltip(gate.name).addTo(layer);
        });
        (campus.offices || []).forEach(function (office) {
            L.marker([office.latitude, office.longitude], { icon: officeIcon(), keyboard: false })
                .bindTooltip(office.label)
                .addTo(layer);
        });
    }

    return {
        load: load,
        boundaryBounds: boundaryBounds,
        lockToCampus: lockToCampus,
        drawOverlay: drawOverlay,
        officeIcon: officeIcon,
        OFFICE_COLOR: OFFICE_COLOR,
        GATE_COLOR: GATE_COLOR,
    };
})();
