<form class="needs-validation address-form mb-4" novalidate>
  <input type="hidden" name="id" value="">

  @if(current_customer_id())
    <div class="form-group mb-4">
      <label class="form-label" for="name">
        <span class="text-danger">*</span>
        {{ __('common/address.name') }}
      </label>
      <input type="text" class="form-control" name="name" value="" required
             placeholder="{{ __('common/address.name') }}"/>
      <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
      __('common/address.name')]) }}</span>
    </div>
  @else
    <div class="row gx-2">
      <div class="col-6">
        <div class="form-group mb-4">
          <label class="form-label" for="name">
            <span class="text-danger">*</span>
            {{ __('common/address.name') }}
          </label>
          <input type="text" class="form-control" name="name" value="" required
                 placeholder="{{ __('common/address.name') }}"/>
          <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
          __('common/address.name')]) }}</span>
        </div>
      </div>
      <div class="col-6">
        <div class="form-group mb-4">
          <label class="form-label" for="email">
            <span class="text-danger">*</span>
            {{ __('common/address.email') }}
          </label>
          <input type="text" class="form-control" name="email" value="" required
                 placeholder="{{ __('common/address.email') }}"/>
          <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
          __('common/address.email')]) }}</span>
        </div>
      </div>
    </div>
  @endif

  <div class="form-group mb-4">
    <label class="form-label" for="email">
      <span class="text-danger">*</span>
      {{ __('common/address.address_1') }}</label>
    <input type="text" class="form-control" name="address_1" value="" required
           placeholder="{{ __('common/address.address_1') }}"/>
    <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
      __('common/address.address_1')]) }}</span>
  </div>
  <div class="row gx-2">
    <div class="col-6">
      <div class="form-group mb-4">
        <label class="form-label" for="Address_1">{{ __('common/address.address_2') }}</label>
        <input type="text" class="form-control" name="address_2" value=""
               placeholder="{{ __('common/address.address_2') }}"/>
      </div>
    </div>
    <div class="col-6">
      <div class="form-group mb-4">
        <label class="form-label" for="zipcode">
          <span class="text-danger">*</span>
          {{ __('common/address.zipcode') }}
        </label>
        <input type="text" class="form-control" name="zipcode" value="" required
               placeholder="{{ __('common/address.zipcode') }}"/>
        <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
          __('common/address.zipcode')]) }}</span>
      </div>
    </div>
    <div class="col-6">
      <div class="form-group mb-4">
        <label class="form-label" for="city">
          <span class="text-danger">*</span>
          {{ __('common/address.city') }}
        </label>
        {{-- zwxc-custom: 城市输入保留原文本字段，仅追加 GeoNames 候选 datalist --}}
        <input type="text" class="form-control" name="city" value="" required placeholder="City"
               list="zwxc-geo-cities" autocomplete="off"/>
        <datalist id="zwxc-geo-cities"></datalist>
        <span class="invalid-feedback" role="alert">
          {{ __('front/common.error_required', ['name' => __('common/address.city')]) }}
        </span>
      </div>
    </div>
    <div class="col-6">
      <div class="form-group mb-4">
        <label class="form-label" for="country_code">
          <span class="text-danger">*</span>
          {{ __('common/address.country') }}
        </label>
        <select class="form-select" name="country_code" required></select>
        <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
          __('common/address.country')]) }}</span>
      </div>
    </div>
    <div class="col-6">
      <div class="form-group mb-4">
        <label class="form-label" for="state">
          <span class="text-danger">*</span>
          {{ __('common/address.state') }}
        </label>
        <select class="form-select" name="state_code" required disabled></select>
        <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
          __('common/address.state')]) }}</span>
      </div>
    </div>
    <div class="col-6">
      <div class="form-group mb-4">
        <label class="form-label" for="phone">
          <span class="text-danger">*</span>
          {{ __('common/address.phone') }}
        </label>
        <input type="text" class="form-control" name="phone" value="" required
               placeholder="{{ __('common/address.phone') }}"/>
        <span class="invalid-feedback" role="alert">{{ __('front/common.error_required', ['name' =>
          __('common/address.phone')]) }}</span>
      </div>
    </div>

    <div class="col-6">
      <div class="form-group mb-4 d-flex gap-3">
        <label class="form-label" for="default">{{__('common/base.default')}}</label>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="default" name="default" value="1">
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-center">
    <button type="button" class="btn btn-primary btn-lg form-submit w-50">{{ __('common/base.submit') }}</button>
  </div>
</form>

@push('footer')
  <script>
    const settingCountryCode = @json(system_setting('country_code') ?? '');
    const settingStateCode = @json(system_setting('state_code') ?? '');
    // zwxc-custom: 访客 IP 定位国家优先作为地址默认国家（后台关闭定位时不生效），无定位时回退系统设置
    const geoAutoDetectEnabled = @json(filter_var(system_setting('geo_auto_detect', '1'), FILTER_VALIDATE_BOOLEAN));
    const geoDetectedCountryCode = geoAutoDetectEnabled ? @json(session('geo_country_code') ?? '') : '';
    const defaultCountryCode = geoDetectedCountryCode || settingCountryCode;
    const geoCityEndpoint = @json(url('customgeo/cities'));

    inno.validateAndSubmitForm('.address-form', function (data) {
      if (typeof updateAddress === 'function') {
        updateAddress(data);
      }
    });

    getCountries();

    if (defaultCountryCode) {
      $('select[name="country_code"]').val(defaultCountryCode);
      getZones(defaultCountryCode);
    }

    $(document).on('change', 'select[name="country_code"]', function () {
      var countryId = $(this).val();
      getZones(countryId);
    });

    // zwxc-custom: 州省变化时刷新城市候选
    $(document).on('change', 'select[name="state_code"]', function () {
      loadGeoCityOptions($('select[name="country_code"]').val(), $(this).val());
    });

    // zwxc-custom: 城市候选（只填充 datalist，不改变原有 city 文本提交）
    function loadGeoCityOptions(countryCode, stateCode) {
      var list = document.getElementById('zwxc-geo-cities');
      if (!list) {
        return;
      }
      list.innerHTML = '';
      if (!countryCode) {
        return;
      }
      var params = {country: countryCode};
      if (stateCode) {
        params.state = stateCode;
      }
      axios.get(geoCityEndpoint, {params: params}).then(function (res) {
        (res.data || []).forEach(function (city) {
          var option = document.createElement('option');
          option.value = city.name;
          list.appendChild(option);
        });
      }).catch(function () {
        // 城市候选失败不影响地址填写
      });
    }

    // Get all country data
    function getCountries() {
      axios.get('{{ front_route('countries.index') }}').then(function (res) {
        var countries = res.data;
        var countrySelect = $('select[name="country_code"]');
        countrySelect.empty();
        countrySelect.append('<option value="">{{ __('common/base.please_choose') }}</option>');
        countries.forEach(function (country) {
          countrySelect.append('<option value="' + country.code + '"' + (country.code == defaultCountryCode ? ' selected' : '') + '>' + country.name + '</option>');
        });
      });
    }

    function getZones(countryId, callback = null) {
      axios.get('{{ front_route('countries.index') }}/' + countryId).then(function (res) {
        var zones = res.data;
        var zoneSelect = $('select[name="state_code"]');
        zoneSelect.empty();

        if (zones.length === 0) {
          zoneSelect.prop('disabled', true);
          zoneSelect.prop('required', false);
          zoneSelect.append('<option value="">N/A</option>');
        } else {
          zoneSelect.prop('disabled', false);
          zoneSelect.prop('required', true);
          zoneSelect.append('<option value="">{{ __('common/base.please_choose') }}</option>');
          zones.forEach(function (zone) {
            zoneSelect.append('<option value="' + zone.code + '">' + zone.name + '</option>');
          });
        }

        // zwxc-custom: 国家切换后刷新城市候选
        loadGeoCityOptions(countryId, '');

        if (typeof callback === 'function') {
          callback();
        }
      });
    }

    function clearForm() {
      const addressForm = $('.address-form');
      addressForm[0].reset();
      addressForm.removeClass('was-validated');

      addressForm.find('.is-valid, .is-invalid').removeClass('is-valid is-invalid');
    }
  </script>
@endpush
