/**
 * NWS CAD Dashboard - Map Integration
 * Leaflet map functionality for call and unit locations
 */

const MapManager = {
    maps: {},
    markers: {},
    
    /**
     * Initialize a map
     */
    initMap(containerId, center = [37.7749, -122.4194], zoom = 12) {
        if (this.maps[containerId]) {
            return this.maps[containerId];
        }
        
        const map = L.map(containerId).setView(center, zoom);
        
        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19
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
        if (!this.maps[containerId] || !call.latitude || !call.longitude) {
            return;
        }
        
        const lat = parseFloat(call.latitude);
        const lon = parseFloat(call.longitude);
        
        if (isNaN(lat) || isNaN(lon)) {
            return;
        }
        
        // Choose marker icon based on priority
        const iconColor = this.getCallIconColor(call.priority);
        const icon = L.divIcon({
            className: 'custom-marker',
            html: `<div style="background-color: ${iconColor}; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-telephone-fill" style="color: white; font-size: 14px;"></i>
            </div>`,
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });
        
        const marker = L.marker([lat, lon], { icon })
            .bindPopup(this.createCallPopup(call))
            .addTo(this.markers[containerId]);
        
        return marker;
    },
    
    /**
     * Add a unit marker to the map
     */
    addUnitMarker(containerId, unit) {
        if (!this.maps[containerId] || !unit.latitude || !unit.longitude) {
            return;
        }
        
        const lat = parseFloat(unit.latitude);
        const lon = parseFloat(unit.longitude);
        
        if (isNaN(lat) || isNaN(lon)) {
            return;
        }
        
        // Choose marker icon based on status
        const iconColor = this.getUnitIconColor(unit.status);
        const icon = L.divIcon({
            className: 'custom-marker',
            html: `<div style="background-color: ${iconColor}; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-truck" style="color: white; font-size: 14px;"></i>
            </div>`,
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });
        
        const marker = L.marker([lat, lon], { icon })
            .bindPopup(this.createUnitPopup(unit))
            .addTo(this.markers[containerId]);
        
        return marker;
    },
    
    /**
     * Get call marker color based on priority
     */
    getCallIconColor(priority) {
        const colors = {
            1: '#dc3545', // Red - High priority
            2: '#ffc107', // Yellow - Medium-high
            3: '#0dcaf0', // Blue - Medium
            4: '#198754'  // Green - Low
        };
        return colors[priority] || '#6c757d';
    },
    
    /**
     * Get unit marker color based on status
     */
    getUnitIconColor(status) {
        const colors = {
            'available': '#198754',  // Green
            'enroute': '#ffc107',    // Yellow
            'onscene': '#dc3545',    // Red
            'offduty': '#6c757d'     // Gray
        };
        return colors[status?.toLowerCase()] || '#6c757d';
    },
    
    /**
     * Create popup HTML for a call
     */
    createCallPopup(call) {
        return `
            <div class="map-popup">
                <h6><i class="bi bi-telephone"></i> Call #${call.id || call.call_id}</h6>
                <div class="mb-2">
                    <strong>Type:</strong> ${call.call_type || 'N/A'}<br>
                    <strong>Priority:</strong> ${Dashboard.getPriorityBadge(call.priority)}<br>
                    <strong>Status:</strong> ${Dashboard.getStatusBadge(call.status)}<br>
                    <strong>Location:</strong> ${call.address || call.location || 'N/A'}<br>
                    <strong>Time:</strong> ${Dashboard.formatTime(call.received_time || call.created_at)}
                </div>
                <button class="btn btn-sm btn-primary" onclick="viewCallDetails(${call.id || call.call_id})">
                    View Details
                </button>
            </div>
        `;
    },
    
    /**
     * Create popup HTML for a unit
     */
    createUnitPopup(unit) {
        return `
            <div class="map-popup">
                <h6><i class="bi bi-truck"></i> ${unit.unit_id || unit.badge_number}</h6>
                <div class="mb-2">
                    <strong>Type:</strong> ${unit.unit_type || 'N/A'}<br>
                    <strong>Status:</strong> ${Dashboard.getStatusBadge(unit.status)}<br>
                    <strong>Agency:</strong> ${unit.agency || 'N/A'}<br>
                    <strong>Current Call:</strong> ${unit.current_call || 'None'}
                </div>
                <button class="btn btn-sm btn-primary" onclick="viewUnitDetails(${unit.id})">
                    View Details
                </button>
            </div>
        `;
    },
    
    /**
     * Fit map to show all markers
     */
    fitToMarkers(containerId) {
        if (!this.markers[containerId] || this.markers[containerId].getLayers().length === 0) {
            return;
        }
        
        const bounds = this.markers[containerId].getBounds();
        this.maps[containerId].fitBounds(bounds, { padding: [50, 50] });
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
     * Add multiple unit markers and fit map
     */
    showUnits(containerId, units) {
        this.clearMarkers(containerId);
        
        if (!units || units.length === 0) {
            return;
        }
        
        units.forEach(unit => {
            this.addUnitMarker(containerId, unit);
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
