/**
 * NWS CAD Dashboard - Map Integration
 * Leaflet map functionality for call and unit locations
 */

const MapManager = {
    maps: {},
    markers: {},
    
    /**
     * Initialize a map
     * Default center: Madison County, Indiana (40.1184° N, 85.6900° W)
     */
    initMap(containerId, center = null, zoom = 11.5) {
        // Use configured center or default to Madison County, Indiana
        center = center || window.MAP_DEFAULT_CENTER || [40.1184, -85.6900];
        if (this.maps[containerId]) {
            return this.maps[containerId];
        }
        
        // Define Madison County, Indiana boundaries (approximate)
        // Southwest corner: ~39.95° N, 85.85° W
        // Northeast corner: ~40.30° N, 85.50° W
        const madisonCountyBounds = [
            [39.90, -85.90],  // Southwest (with buffer)
            [40.35, -85.45]   // Northeast (with buffer)
        ];
        
        const map = L.map(containerId, {
            maxBounds: madisonCountyBounds,
            maxBoundsViscosity: 1.0,  // Makes bounds "hard" - prevents dragging outside
            minZoom: 10.5,             // Low enough for fitBounds to zoom out and fit markers spread across Madison County
            maxZoom: 21,               // Allow zooming in close for street detail
            zoomSnap: 0.5,             // Allow half-level zoom increments
            zoomDelta: 0.5             // Zoom by 0.5 levels when using controls
        }).setView(center, zoom);
        
        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 21,               // Match map maxZoom
            minZoom: 10.5              // Match map minZoom (fractional)
        }).addTo(map);
        
        this.maps[containerId] = map;
        this.markers[containerId] = L.layerGroup().addTo(map);
        
        return map;
    },
    
    /**
     * Clear all markers from a map
     */
    clearMarkers(containerId) {
        if (this.markers[containerId]) {
            this.markers[containerId].clearLayers();
        }
    },
    
    /**
     * Add a call marker to the map
     */
    addCallMarker(containerId, call) {
        console.log('[MapManager] Adding call marker:', {containerId, callId: call.id, lat: call.latitude, lng: call.longitude});

        if (!this.maps[containerId]) {
            console.warn('[MapManager] Cannot add marker - map not initialized');
            return;
        }

        const lat = parseFloat(call.latitude);
        const lon = parseFloat(call.longitude);

        // Aegis uses -361,-361 as a sentinel for unmappable calls; any out-of-range value would poison fitBounds
        if (!Number.isFinite(lat) || !Number.isFinite(lon)
            || lat < -90 || lat > 90 || lon < -180 || lon > 180) {
            console.warn('[MapManager] Skipping marker with invalid coordinates:', lat, lon);
            return;
        }
        
        console.log('[MapManager] Creating marker at:', lat, lon);
        
        // Choose marker color class based on priority. Styles live in dashboard.css
        // because CSP style-src no longer allows inline style="" attributes.
        const colorClass = this.getCallIconColorClass(call.priority);
        const icon = L.divIcon({
            className: 'custom-marker',
            html: `<div class="marker-circle ${colorClass}"><i class="bi bi-telephone-fill"></i></div>`,
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });
        
        const marker = L.marker([lat, lon], { icon })
            .bindPopup(call.popupContent || this.createCallPopup(call))
            .addTo(this.markers[containerId]);
        
        console.log('[MapManager] Marker added successfully');
        return marker;
    },
    
    /**
     * Get call marker color class based on priority.
     * Concrete colors live in dashboard.css under .marker-circle--{name}.
     */
    getCallIconColorClass(priority) {
        const classes = {
            1: 'marker-circle--red',     // High priority
            2: 'marker-circle--yellow',  // Medium-high
            3: 'marker-circle--blue',    // Medium
            4: 'marker-circle--green'    // Low
        };
        return classes[priority] || 'marker-circle--gray';
    },


    /**
     * Create popup HTML for a call
     */
    createCallPopup(call) {
        // Extract first values from arrays if available
        const callType = call.call_types?.[0] || call.call_type || call.nature_of_call || 'N/A';
        const priority = call.priorities?.[0] || call.priority || 'Normal';
        const status = call.statuses?.[0] || call.status || ((call.closed_flag || call.is_stale) ? 'Closed' : 'Active');
        const time = call.create_datetime || call.received_time || call.created_at || 'N/A';
        
        return `
            <div class="map-popup">
                <h6><i class="bi bi-telephone"></i> Call #${Dashboard.escapeHtml(call.call_number || call.id || call.call_id)}</h6>
                <div class="mb-2">
                    <strong>Type:</strong> ${Dashboard.escapeHtml(callType)}<br>
                    <strong>Priority:</strong> ${Dashboard.getPriorityBadge(priority)}<br>
                    <strong>Status:</strong> ${Dashboard.getStatusBadge(status)}<br>
                    <strong>Location:</strong> ${Dashboard.escapeHtml(call.address || call.location?.address || 'N/A')}<br>
                    <strong>Time:</strong> ${Dashboard.formatTime(time)}
                </div>
                <button class="btn btn-sm btn-primary" data-popup-action="view-call" data-call-id="${call.id || call.call_id}">
                    View Details
                </button>
            </div>
        `;
    },
    
    /**
     * Fit map to show all markers
     */
    fitToMarkers(containerId) {
        console.log('[MapManager] Fitting map to markers for:', containerId);
        
        if (!this.markers[containerId]) {
            console.error('[MapManager] No markers layer found for:', containerId);
            return;
        }
        
        const layers = this.markers[containerId].getLayers();
        console.log('[MapManager] Number of markers:', layers.length);
        
        if (layers.length === 0) {
            console.warn('[MapManager] No markers to fit to');
            return;
        }
        
        try {
            // Create a FeatureGroup from the layers to get bounds
            const group = L.featureGroup(layers);
            const bounds = group.getBounds();
            console.log('[MapManager] Bounds:', bounds);
            
            if (bounds.isValid()) {
                this.maps[containerId].fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
                console.log('[MapManager] Map fitted to bounds successfully');
            } else {
                console.error('[MapManager] Invalid bounds');
            }
        } catch (error) {
            console.error('[MapManager] Error fitting bounds:', error);
        }
    },
    
    /**
     * Add multiple call markers and fit map
     */
    showCalls(containerId, calls) {
        this.clearMarkers(containerId);
        
        if (!calls || calls.length === 0) {
            return;
        }
        
        calls.forEach(call => {
            this.addCallMarker(containerId, call);
        });
        
        this.fitToMarkers(containerId);
    },
    
    /**
     * Resize map (useful after container size changes)
     */
    resize(containerId) {
        if (this.maps[containerId]) {
            this.maps[containerId].invalidateSize();
        }
    }
};

// Make globally available
window.MapManager = MapManager;

// Click delegation for popup action buttons. Leaflet popups are reattached to the
// DOM each time they open, so binding handlers per-popup would leak listeners;
// one document-level listener covers every popup the MapManager renders.
// CSP no longer permits inline onclick handlers — see SecurityHeaders::setContentSecurityPolicy.
document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-popup-action]');
    if (!target) return;

    if (target.dataset.popupAction === 'view-call') {
        const id = parseInt(target.dataset.callId, 10);
        if (Number.isFinite(id) && typeof window.viewCallDetails === 'function') {
            window.viewCallDetails(id);
        }
    }
});
