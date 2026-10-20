jQuery(document).ready(function ($) {

    let map, truckMarker, routeLine;

    $('#stunnz-tracker-form').on('submit', function (e) {
        e.preventDefault();

        const trackingCode = $('#stunnz-tracking-input').val().trim();
        if (!trackingCode) {
            alert('Please enter a tracking code.');
            return;
        }

        $('#stunnz-map').html('<p style="text-align:center;padding:20px;">Loading tracking data...</p>');

        fetch(`${stunnz_ajax.rest_url}?tracking_code=${encodeURIComponent(trackingCode)}`)
            .then(res => res.json())
            .then(data => {
                console.log('Tracking response:', data);
                if (!data.found) {
                    $('#stunnz-map').html('<p style="text-align:center;padding:20px;color:red;">Tracking code not found.</p>');
                    return;
                }

                // Load map style and then build the map
                fetch(`${stunnz_ajax.plugin_url}assets/map-style.json`)
                    .then(r => r.json())
                    .then(styleData => {
                        initMap(data, styleData);
                    })
                    .catch(() => {
                        console.warn('Map style not loaded, using default.');
                        initMap(data, null);
                    });
            })
            .catch(err => {
                console.error('Fetch error:', err);
                $('#stunnz-map').html('<p style="text-align:center;padding:20px;color:red;">Error loading tracking data.</p>');
            });
    });

    // ========== Initialize Google Map with Checkpoints ==========
    function initMap(data, mapStyle) {
        $('#stunnz-map').html(''); // Clear loading text

        map = new google.maps.Map(document.getElementById('stunnz-map'), {
            zoom: 4,
            center: { lat: 39.8283, lng: -98.5795 }, // USA center
            styles: mapStyle || null
        });

        const geocoder = new google.maps.Geocoder();
        const checkpoints = data.checkpoints || [];
        const routeCoords = [];

        if (!checkpoints.length) {
            $('#stunnz-map').html('<p style="text-align:center;padding:20px;">No checkpoints available for this shipment.</p>');
            return;
        }

        // Convert each checkpoint address to LatLng
        let processIndex = 0;
        function nextPoint() {
            if (processIndex >= checkpoints.length) {
                drawRoute(routeCoords);
                return;
            }

            const place = checkpoints[processIndex].place;
            geocoder.geocode({ address: place }, function (results, status) {
                if (status === 'OK' && results[0]) {
                    const loc = results[0].geometry.location;
                    const marker = new google.maps.Marker({
                        map: map,
                        position: loc,
                        label: `${processIndex + 1}`,
                    });
                    routeCoords.push(loc);

                    if (processIndex === 0) map.setCenter(loc);
                }
                processIndex++;
                setTimeout(nextPoint, 800); // Delay to avoid quota issues
            });
        }
        nextPoint();
    }

    // ========== Draw Polyline Route ==========
    function drawRoute(routeCoords) {
        if (routeLine) routeLine.setMap(null);
        routeLine = new google.maps.Polyline({
            path: routeCoords,
            geodesic: true,
            strokeColor: '#4285F4',
            strokeOpacity: 0.8,
            strokeWeight: 4
        });
        routeLine.setMap(map);

        // Truck icon animation (simple)
        if (routeCoords.length >= 2) {
            let i = 0;
            truckMarker = new google.maps.Marker({
                map: map,
                position: routeCoords[0],
                icon: {
                    url: `${stunnz_ajax.plugin_url}assets/truck-icon.png`,
                    scaledSize: new google.maps.Size(40, 40)
                }
            });

            const interval = setInterval(() => {
                i++;
                if (i >= routeCoords.length) {
                    clearInterval(interval);
                    return;
                }
                truckMarker.setPosition(routeCoords[i]);
            }, 1500);
        }
    }

});