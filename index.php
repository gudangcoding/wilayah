<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wilayah Indonesia - Select Chain + Leaflet</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet" />
  <style>
    body { padding-top: 24px; }
    .select2-container { width: 100% !important; }
    #map { width: 100%; height: 60vh; }
  </style>
</head>
<body>
  <div class="container">
    <h1 class="h3 mb-3">Select Chain Wilayah Indonesia</h1>
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Kode Pos</label>
        <select id="kodepos" class="form-select"></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Provinsi</label>
        <select id="provinsi" class="form-select"></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Kabupaten/Kota</label>
        <select id="kabupaten" class="form-select" disabled></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Kecamatan</label>
        <select id="kecamatan" class="form-select" disabled></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Desa/Kelurahan</label>
        <select id="desa" class="form-select" disabled></select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Cari Alamat (Nominatim)</label>
        <select id="nominatim" class="form-select"></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Latitude</label>
        <input id="lat" class="form-control" />
      </div>
      <div class="col-md-3">
        <label class="form-label">Longitude</label>
        <input id="lng" class="form-control" />
      </div>
    </div>

    <div class="mt-4">
      <div id="map"></div>
    </div>
    <div class="row g-3 mt-3">
      <div class="col-12">
        <label class="form-label">Alamat (Reverse Geocode)</label>
        <textarea id="alamat" class="form-control" rows="3"></textarea>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const apiUrl = 'api.php';

    const map = L.map('map').setView([-2.5, 118], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    let marker = null;

    function moveTo(lat, lng, label, zoom) {
      if (lat == null || lng == null) return;
      const pos = [lat, lng];
      if (marker) { marker.setLatLng(pos).setPopupContent(label).openPopup(); }
      else { marker = L.marker(pos, { draggable: true }).addTo(map).bindPopup(label).openPopup(); 
        marker.on('dragend', function () {
          const c = marker.getLatLng();
          updateLatLngFields(c.lat, c.lng);
          reverseGeocode(c.lat, c.lng);
          updateChainFromLatLng(c.lat, c.lng);
        });
      }
      const z = (zoom !== undefined) ? zoom : 10;
      map.setView(pos, z, { animate: true });
    }

    function createSelect2($el, level, getParentCode) {
      $el.select2({
        placeholder: 'Pilih...',
        allowClear: true,
        ajax: {
          url: apiUrl,
          dataType: 'json',
          delay: 250,
          data: function (params) {
            return {
              level: level,
              parent: getParentCode ? getParentCode() : null,
              q: params.term || '',
              page: params.page || 1,
              pageSize: 50
            };
          },
          processResults: function (data, params) {
            params.page = params.page || 1;
            return {
              results: data.results,
              pagination: { more: data.pagination && data.pagination.more }
            };
          },
          cache: true
        },
        templateResult: function (item) {
          if (item.loading) return item.text;
          const name = item.text || '';
          const coords = (item.latitude && item.longitude) ? ` (${item.latitude.toFixed(4)}, ${item.longitude.toFixed(4)})` : '';
          return `${name}${coords}`;
        },
        templateSelection: function (item) {
          return item.text || item.id;
        },
        width: '100%'
      });
    }

    const $prov = $('#provinsi');
    const $kab = $('#kabupaten');
    const $kec = $('#kecamatan');
    const $des = $('#desa');
    const $pos = $('#kodepos');
    const $nomi = $('#nominatim');
    const $lat = $('#lat');
    const $lng = $('#lng');
    const $alamat = $('#alamat');

    createSelect2($prov, 0, null);
    createSelect2($kab, 1, () => $prov.val());
    createSelect2($kec, 2, () => $kab.val());
    createSelect2($des, 3, () => $kec.val());

    function createSelect2Postal($el) {
      $el.select2({
        placeholder: 'Cari kode pos...',
        allowClear: true,
        ajax: {
          url: apiUrl,
          dataType: 'json',
          delay: 250,
          data: function (params) {
            return {
              mode: 'postal',
              q: params.term || '',
              page: params.page || 1,
              pageSize: 50
            };
          },
          processResults: function (data, params) {
            params.page = params.page || 1;
            return {
              results: data.results,
              pagination: { more: data.pagination && data.pagination.more }
            };
          },
          cache: true
        },
        templateResult: function (item) {
          if (item.loading) return item.text;
          const name = item.text || '';
          const coords = (item.latitude && item.longitude) ? ` (${item.latitude.toFixed(4)}, ${item.longitude.toFixed(4)})` : '';
          return `${name}${coords}`;
        },
        templateSelection: function (item) {
          return item.text || item.id;
        },
        width: '100%'
      });
    }
    createSelect2Postal($pos);

    function createSelect2Nominatim($el) {
      $el.select2({
        placeholder: 'Cari alamat...',
        allowClear: true,
        ajax: {
          url: 'https://nominatim.openstreetmap.org/search',
          dataType: 'json',
          delay: 300,
          data: function (params) {
            return {
              format: 'jsonv2',
              q: params.term || '',
              countrycodes: 'id',
              addressdetails: 1,
              limit: 10
            };
          },
          processResults: function (data) {
            const results = (data || []).map(function (item) {
              return {
                id: item.place_id,
                text: item.display_name,
                display_name: item.display_name,
                latitude: parseFloat(item.lat),
                longitude: parseFloat(item.lon)
              };
            });
            return { results: results };
          },
          cache: true
        },
        templateResult: function (item) {
          if (item.loading) return item.text;
          const name = item.text || '';
          const coords = (item.latitude && item.longitude) ? ` (${item.latitude.toFixed(4)}, ${item.longitude.toFixed(4)})` : '';
          return `${name}${coords}`;
        },
        templateSelection: function (item) {
          return item.text || item.id;
        },
        width: '100%'
      });
    }
    createSelect2Nominatim($nomi);

    function updateLatLngFields(lat, lng) {
      $lat.val(lat);
      $lng.val(lng);
    }

    function reverseGeocode(lat, lng) {
      const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&addressdetails=1`;
      fetch(url).then(r => r.json()).then(data => {
        const name = (data && data.display_name) ? data.display_name : '';
        $alamat.val(name);
      }).catch(() => {});
    }

    function updateChainFromLatLng(lat, lng) {
      $.getJSON(apiUrl, { mode: 'nearest', lat: lat, lng: lng }).done(function (chain) {
        if (chain && chain.prov && chain.kab && chain.kec && chain.desa) {
          setSelect2Value($prov, chain.prov);
          $kab.prop('disabled', false);
          setSelect2Value($kab, chain.kab);
          $kec.prop('disabled', false);
          setSelect2Value($kec, chain.kec);
          $des.prop('disabled', false);
          setSelect2Value($des, chain.desa);
        }
      });
    }

    function resetBelow($from) {
      if ($from.is($prov)) {
        $kab.val(null).trigger('change').prop('disabled', !$prov.val());
        $kec.val(null).trigger('change').prop('disabled', true);
        $des.val(null).trigger('change').prop('disabled', true);
      } else if ($from.is($kab)) {
        $kec.val(null).trigger('change').prop('disabled', !$kab.val());
        $des.val(null).trigger('change').prop('disabled', true);
      } else if ($from.is($kec)) {
        $des.val(null).trigger('change').prop('disabled', !$kec.val());
      }
    }

    $prov.on('change', function () {
      resetBelow($prov);
    }).on('select2:select', function (e) {
      const d = e.params.data;
      moveTo(d.latitude, d.longitude, `Provinsi: ${d.text}`, 10);
    }).on('select2:clear', function () {
      resetBelow($prov);
    });

    $kab.on('change', function () {
      resetBelow($kab);
    }).on('select2:select', function (e) {
      const d = e.params.data;
      moveTo(d.latitude, d.longitude, `Kab/Kota: ${d.text}`, 10);
    }).on('select2:clear', function () {
      resetBelow($kab);
    });

    $kec.on('change', function () {
      resetBelow($kec);
    }).on('select2:select', function (e) {
      const d = e.params.data;
      moveTo(d.latitude, d.longitude, `Kecamatan: ${d.text}`, 10);
    }).on('select2:clear', function () {
      resetBelow($kec);
    });

    $des.on('select2:select', function (e) {
      const d = e.params.data;
      moveTo(d.latitude, d.longitude, `Desa: ${d.text}`, 10);
    });

    function setSelect2Value($select, item) {
      const option = new Option(item.text, item.id, true, true);
      $select.append(option).trigger('change');
    }

    $pos.on('select2:select', function (e) {
      const desaCode = e.params.data.id;
      $.getJSON(apiUrl, { mode: 'chain', code: desaCode }).done(function (chain) {
        if (chain.prov && chain.kab && chain.kec && chain.desa) {
          setSelect2Value($prov, chain.prov);
          $kab.prop('disabled', false);
          setSelect2Value($kab, chain.kab);
          $kec.prop('disabled', false);
          setSelect2Value($kec, chain.kec);
          $des.prop('disabled', false);
          setSelect2Value($des, chain.desa);
          moveTo(chain.desa.latitude, chain.desa.longitude, `Desa: ${chain.desa.text}`, 18);
          updateLatLngFields(chain.desa.latitude, chain.desa.longitude);
          reverseGeocode(chain.desa.latitude, chain.desa.longitude);
        }
      });
    }).on('select2:clear', function () {
      $prov.val(null).trigger('change');
      $kab.val(null).trigger('change').prop('disabled', true);
      $kec.val(null).trigger('change').prop('disabled', true);
      $des.val(null).trigger('change').prop('disabled', true);
    });

    $nomi.on('select2:select', function (e) {
      const d = e.params.data;
      moveTo(d.latitude, d.longitude, d.text, 18);
      updateLatLngFields(d.latitude, d.longitude);
      $alamat.val(d.display_name || d.text || '');
      updateChainFromLatLng(d.latitude, d.longitude);
    }).on('select2:clear', function () {
      $alamat.val('');
    });

    $kab.prop('disabled', true);
    $kec.prop('disabled', true);
    $des.prop('disabled', true);
  </script>
</body>
</html>
