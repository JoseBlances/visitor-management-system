// Admin "Campus Map" page: draw the campus boundary and place gates and office pins.
(function () {
    "use strict";

    const officeNames = {
        IT: "IT Department",
        IS: "IS Department",
        CS: "CS Department",
        DEANS: "Dean's Office",
        TECH_SUPPORT: "Tech Support",
    };
    const state = {
        map: null,
        editLayer: null,
        mode: "boundary",
        boundary: [],
        gates: [],
        offices: {},
        dirty: false,
        loaded: false,
    };

    function element(id) {
        return document.getElementById(id);
    }

    function showMessage(message, isError) {
        const box = element("campusSetupMessage");
        box.textContent = message || "";
        box.hidden = !message;
        box.classList.toggle("is-error", Boolean(isError));
        box.classList.toggle("is-success", Boolean(message) && !isError);
    }

    function markDirty() {
        state.dirty = true;
        element("campusSetupStatus").textContent = "Unsaved changes";
        showMessage("");
    }

    function vertexIcon(className) {
        return L.divIcon({ className: className, html: "<span></span>", iconSize: [14, 14], iconAnchor: [7, 7] });
    }

    function draggableMarker(latLng, icon, label, onMove) {
        const marker = L.marker(latLng, { icon: icon, draggable: true, keyboard: false });
        if (label) {
            marker.bindTooltip(label, { permanent: true, direction: "top", offset: [0, -8], className: "campus-setup-label" });
        }
        marker.on("dragend", function () {
            const position = marker.getLatLng();
            onMove([position.lat, position.lng]);
            markDirty();
            render();
        });
        return marker;
    }

    function render() {
        if (!state.map) {
            return;
        }
        state.editLayer.clearLayers();

        if (state.boundary.length >= 3) {
            L.polygon(state.boundary, {
                color: CampusMap.OFFICE_COLOR, weight: 2, fillOpacity: 0.06, interactive: false,
            }).addTo(state.editLayer);
        } else if (state.boundary.length === 2) {
            L.polyline(state.boundary, { color: CampusMap.OFFICE_COLOR, weight: 2, interactive: false }).addTo(state.editLayer);
        }
        state.boundary.forEach(function (corner, index) {
            draggableMarker(corner, vertexIcon("campus-vertex-pin"), null, function (point) {
                state.boundary[index] = point;
            }).addTo(state.editLayer);
        });
        state.gates.forEach(function (gate, index) {
            draggableMarker([gate.latitude, gate.longitude], vertexIcon("campus-gate-pin"), gate.name, function (point) {
                state.gates[index].latitude = point[0];
                state.gates[index].longitude = point[1];
            }).addTo(state.editLayer);
        });
        Object.keys(state.offices).forEach(function (code) {
            draggableMarker(state.offices[code], CampusMap.officeIcon(), officeNames[code] || code, function (point) {
                state.offices[code] = point;
            }).addTo(state.editLayer);
        });

        element("campusCornerCount").textContent = state.boundary.length + " corner" + (state.boundary.length === 1 ? "" : "s")
            + (state.boundary.length > 0 && state.boundary.length < 3 ? " — add at least 3" : "");
        renderGateList();
        renderOfficeList();
    }

    function listRow(title, detail, onRemove) {
        const row = document.createElement("div");
        row.className = "campus-setup-row";
        const copy = document.createElement("div");
        const name = document.createElement("strong");
        name.textContent = title;
        const info = document.createElement("span");
        info.textContent = detail;
        copy.append(name, info);
        row.appendChild(copy);
        if (onRemove) {
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "campus-setup-remove";
            remove.setAttribute("aria-label", "Remove " + title);
            remove.textContent = "×";
            remove.addEventListener("click", function () {
                onRemove();
                markDirty();
                render();
            });
            row.appendChild(remove);
        }
        return row;
    }

    function renderGateList() {
        const host = element("campusGateList");
        host.replaceChildren();
        if (state.gates.length === 0) {
            host.appendChild(listRow("No gates yet", "Add each campus entrance visitors can use.", null));
            return;
        }
        state.gates.forEach(function (gate, index) {
            host.appendChild(listRow(gate.name, gate.latitude.toFixed(5) + ", " + gate.longitude.toFixed(5), function () {
                state.gates.splice(index, 1);
            }));
        });
    }

    function renderOfficeList() {
        const host = element("campusOfficeList");
        host.replaceChildren();
        Object.keys(officeNames).forEach(function (code) {
            const point = state.offices[code];
            host.appendChild(listRow(
                officeNames[code],
                point ? "Placed · " + point[0].toFixed(5) + ", " + point[1].toFixed(5) : "Not placed yet",
                point ? function () { delete state.offices[code]; } : null
            ));
        });
    }

    function setMode(mode) {
        state.mode = mode;
        document.querySelectorAll("[data-campus-mode]").forEach(function (button) {
            button.classList.toggle("is-active", button.getAttribute("data-campus-mode") === mode);
        });
        document.querySelectorAll("[data-campus-panel]").forEach(function (panel) {
            panel.hidden = panel.getAttribute("data-campus-panel") !== mode;
        });
    }

    function handleMapClick(event) {
        const point = [event.latlng.lat, event.latlng.lng];
        if (state.mode === "boundary") {
            state.boundary.push(point);
        } else if (state.mode === "gates") {
            const input = element("campusGateName");
            const name = input.value.trim();
            if (!name) {
                showMessage("Type the gate name first, then click where the gate is.", true);
                input.focus();
                return;
            }
            if (state.gates.some(function (gate) { return gate.name.toLowerCase() === name.toLowerCase(); })) {
                showMessage("A gate named \"" + name + "\" already exists.", true);
                return;
            }
            state.gates.push({ name: name, latitude: point[0], longitude: point[1] });
            input.value = "";
        } else {
            const select = element("campusOfficeSelect");
            state.offices[select.value] = point;
            // Move on to the next office that still needs a pin.
            const next = Object.keys(officeNames).find(function (code) { return !state.offices[code]; });
            if (next) {
                select.value = next;
            }
        }
        markDirty();
        render();
    }

    function applyCampus(campus) {
        state.boundary = campus && Array.isArray(campus.boundary) ? campus.boundary.map(function (p) { return [Number(p[0]), Number(p[1])]; }) : [];
        state.gates = campus && Array.isArray(campus.gates)
            ? campus.gates.map(function (g) { return { name: g.name, latitude: Number(g.latitude), longitude: Number(g.longitude) }; })
            : [];
        state.offices = {};
        (campus && campus.offices || []).forEach(function (office) {
            state.offices[office.code] = [Number(office.latitude), Number(office.longitude)];
        });
        state.dirty = false;
        element("campusSetupStatus").textContent = campus && campus.updated_at ? "Saved " + campus.updated_at : "Not set up yet";
        render();
        const bounds = CampusMap.boundaryBounds(campus);
        if (bounds) {
            state.map.fitBounds(bounds, { padding: [30, 30] });
        }
    }

    async function initialize() {
        if (state.map) {
            state.map.invalidateSize();
            return;
        }
        if (typeof L === "undefined") {
            showMessage("The map library could not load. Check your internet connection and refresh the page.", true);
            return;
        }
        state.map = L.map("campusSetupMap", CampusMap.mapOptions({ doubleClickZoom: false })).setView([10.7177, 122.5559], 17);
        CampusMap.addBaseLayer(state.map);
        CampusMap.addScale(state.map);
        // Full view keeps the tools panel beside the map, for placing pins precisely.
        CampusMap.addExpandControl(state.map, document.querySelector(".campus-setup-layout"));
        state.editLayer = L.layerGroup().addTo(state.map);
        state.map.on("click", handleMapClick);
        render();
        const campus = await CampusMap.load();
        state.loaded = true;
        applyCampus(campus);
    }

    async function save() {
        if (state.boundary.length < 3) {
            setMode("boundary");
            showMessage("Draw the campus boundary with at least 3 corners before saving.", true);
            return;
        }
        const button = element("campusSaveBtn");
        button.disabled = true;
        try {
            const response = await fetch("admin_save_campus_map.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    boundary: state.boundary,
                    gates: state.gates,
                    offices: Object.keys(state.offices).map(function (code) {
                        return { code: code, latitude: state.offices[code][0], longitude: state.offices[code][1] };
                    }),
                }),
            });
            const data = await response.json();
            if (!data || !data.success) {
                showMessage((data && data.message) || "Could not save the campus map.", true);
                return;
            }
            applyCampus(data.campus);
            showMessage("Campus map saved. Maps and route analytics now use this boundary.", false);
            document.dispatchEvent(new CustomEvent("campus:updated"));
        } catch (error) {
            showMessage("Could not reach the server.", true);
        } finally {
            button.disabled = false;
        }
    }

    function fillOfficeSelect() {
        const select = element("campusOfficeSelect");
        const current = select.value;
        select.replaceChildren();
        Object.keys(officeNames).forEach(function (code) {
            const option = document.createElement("option");
            option.value = code;
            option.textContent = officeNames[code];
            select.appendChild(option);
        });
        if (officeNames[current]) {
            select.value = current;
        }
    }

    // Departments come from the directory, so new ones can get a pin. The list above
    // stays if the directory cannot be loaded.
    function loadOfficeNames() {
        fetch("office_directory.php?all=1", { credentials: "same-origin", cache: "no-store" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success || !Array.isArray(data.offices) || !data.offices.length) {
                    return;
                }
                Object.keys(officeNames).forEach(function (code) { delete officeNames[code]; });
                data.offices.forEach(function (office) {
                    officeNames[office.code] = office.name + (office.is_active ? "" : " (archived)");
                });
                fillOfficeSelect();
                render();
            })
            .catch(function () {});
    }

    fillOfficeSelect();
    loadOfficeNames();
    document.addEventListener("departments:updated", loadOfficeNames);
    document.querySelectorAll("[data-campus-mode]").forEach(function (button) {
        button.addEventListener("click", function () {
            setMode(button.getAttribute("data-campus-mode"));
        });
    });
    element("campusUndoCornerBtn").addEventListener("click", function () {
        if (state.boundary.length > 0) {
            state.boundary.pop();
            markDirty();
            render();
        }
    });
    element("campusClearBoundaryBtn").addEventListener("click", function () {
        if (state.boundary.length > 0 && window.confirm("Remove all boundary corners?")) {
            state.boundary = [];
            markDirty();
            render();
        }
    });
    element("campusSaveBtn").addEventListener("click", save);
    window.addEventListener("beforeunload", function (event) {
        if (state.dirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    document.addEventListener("admin:viewchange", function (event) {
        if (event.detail && event.detail.view === "campus") {
            initialize();
        }
    });
})();
