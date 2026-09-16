@php
    $specialistProfile = isset($user) ? $user->specialistProfile : null;
    $otherDocuments = array_values((array) ($specialistProfile?->other_documents_json ?? []));
    $showSpecialistDocuments = ($defaultRole ?? old('role', request('role'))) === 'sale';
@endphp

@if($canManageSpecialistDocuments ?? false)
    <div id="specialist-documents-wrapper" class="card border-primary mb-4 {{ $showSpecialistDocuments ? '' : 'd-none' }}">
        <div class="card-header bg-primary text-white">
            Hồ sơ pháp lý chuyên viên Sale
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                Tài liệu được lưu ở vùng riêng tư. Chỉ Super Admin, Operation Admin và chính chuyên viên được tải về.
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="contract_file" class="form-label">File hợp đồng</label>
                    <input type="file"
                        class="form-control @error('contract_file') is-invalid @enderror"
                        id="contract_file"
                        name="contract_file"
                        accept=".pdf,.doc,.docx">
                    <div class="form-text">PDF, DOC hoặc DOCX; tối đa 10 MB.</div>
                    @error('contract_file')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($specialistProfile?->contract_file_url)
                        <div class="mt-2 d-flex align-items-center gap-3">
                            <a href="{{ route('admin.users.documents.download', [$user, 'contract']) }}">
                                Tải hợp đồng hiện tại
                            </a>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                    id="remove_contract_file" name="remove_contract_file">
                                <label class="form-check-label" for="remove_contract_file">Xóa file</label>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <label for="other_documents" class="form-label">Giấy tờ khác</label>
                    <input type="file"
                        class="form-control @error('other_documents') is-invalid @enderror @error('other_documents.*') is-invalid @enderror"
                        id="other_documents"
                        name="other_documents[]"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                        multiple>
                    <div class="form-text">Tối đa 10 file mỗi lần, mỗi file tối đa 10 MB.</div>
                    @error('other_documents')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    @error('other_documents.*')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($otherDocuments !== [])
                        <div class="mt-2">
                            @foreach($otherDocuments as $index => $document)
                                <div class="d-flex align-items-center gap-3 mb-1">
                                    <a href="{{ route('admin.users.documents.download', [$user, 'other', $index]) }}">
                                        Giấy tờ khác #{{ $index + 1 }}
                                    </a>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1"
                                            id="remove_other_document_{{ $index }}"
                                            name="remove_other_documents[{{ $index }}]">
                                        <label class="form-check-label" for="remove_other_document_{{ $index }}">Xóa</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <label for="id_card_front" class="form-label">CCCD mặt trước</label>
                    <input type="file"
                        class="form-control @error('id_card_front') is-invalid @enderror"
                        id="id_card_front"
                        name="id_card_front"
                        accept=".jpg,.jpeg,.png,.webp,image/*">
                    <div class="form-text">JPG, PNG hoặc WEBP; tối đa 5 MB.</div>
                    @error('id_card_front')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($specialistProfile?->id_card_front_url)
                        <div class="mt-2 d-flex align-items-center gap-3">
                            <a href="{{ route('admin.users.documents.download', [$user, 'id-card-front']) }}">
                                Tải CCCD mặt trước
                            </a>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                    id="remove_id_card_front" name="remove_id_card_front">
                                <label class="form-check-label" for="remove_id_card_front">Xóa ảnh</label>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="col-md-6">
                    <label for="id_card_back" class="form-label">CCCD mặt sau</label>
                    <input type="file"
                        class="form-control @error('id_card_back') is-invalid @enderror"
                        id="id_card_back"
                        name="id_card_back"
                        accept=".jpg,.jpeg,.png,.webp,image/*">
                    <div class="form-text">JPG, PNG hoặc WEBP; tối đa 5 MB.</div>
                    @error('id_card_back')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($specialistProfile?->id_card_back_url)
                        <div class="mt-2 d-flex align-items-center gap-3">
                            <a href="{{ route('admin.users.documents.download', [$user, 'id-card-back']) }}">
                                Tải CCCD mặt sau
                            </a>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                    id="remove_id_card_back" name="remove_id_card_back">
                                <label class="form-check-label" for="remove_id_card_back">Xóa ảnh</label>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
