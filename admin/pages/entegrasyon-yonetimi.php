<?php
require_once __DIR__ . '/../auth.php';
requireAuth();
$user = Auth::user();
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'list_entegrasyon':
                $rows = $db->fetchAll("SELECT e.*, (SELECT COUNT(*) FROM EntegrasyonKanallari k WHERE k.Kanal_Entegrasyon_Id=e.Entegrasyon_Id AND k.Durum=1) as KanalSayisi FROM Entegrasyon e ORDER BY e.Entegrasyon_Sira");
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;
            case 'list_kanal':
                $eid = intval($_POST['entegrasyon_id']??0);
                $rows = $db->fetchAll("SELECT * FROM EntegrasyonKanallari WHERE Kanal_Entegrasyon_Id=? AND Durum=1 ORDER BY Kanal_Id",[$eid]);
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;
            case 'save_entegrasyon':
                $id       = intval($_POST['id']??0);
                $adi      = trim($_POST['Entegrasyon_Adi']??'');
                $kod      = trim($_POST['Entegrasyon_Kod']??'');
                $aciklama = trim($_POST['Entegrasyon_Aciklama']??'');
                $ikon     = trim($_POST['Entegrasyon_Ikon']??'');
                $renk     = trim($_POST['Entegrasyon_Renk']??'#000000');
                $sira     = intval($_POST['Entegrasyon_Sira']??0);
                $durum    = intval($_POST['Entegrasyon_Durum']??1);
                $baseUrl  = trim($_POST['Entegrasyon_BaseUrl']??'') ?: null;
                $docsUrl  = trim($_POST['Entegrasyon_DocsUrl']??'') ?: null;
                if (!$adi||!$kod) throw new Exception('Ad ve Kod zorunludur.');
                if ($id>0) {
                    $db->execute("UPDATE Entegrasyon SET Entegrasyon_Adi=?,Entegrasyon_Kod=?,Entegrasyon_Aciklama=?,Entegrasyon_Ikon=?,Entegrasyon_Renk=?,Entegrasyon_Sira=?,Entegrasyon_Durum=?,Entegrasyon_BaseUrl=?,Entegrasyon_DocsUrl=?,GuncelleyenKullanici=?,GuncellemeTarihi=GETDATE(),Durum=? WHERE Entegrasyon_Id=?",
                        [$adi,$kod,$aciklama,$ikon,$renk,$sira,$durum,$baseUrl,$docsUrl,$user['kullanici_id'],$durum,$id]);
                    echo json_encode(['success'=>true,'message'=>'Güncellendi.']);
                } else {
                    $db->execute("INSERT INTO Entegrasyon(Entegrasyon_Adi,Entegrasyon_Kod,Entegrasyon_Aciklama,Entegrasyon_Ikon,Entegrasyon_Renk,Entegrasyon_Sira,Entegrasyon_Durum,Entegrasyon_BaseUrl,Entegrasyon_DocsUrl,OlusturanKullanici,OlusturmaTarihi,GuncelleyenKullanici,GuncellemeTarihi,Durum) VALUES(?,?,?,?,?,?,?,?,?,?,GETDATE(),?,GETDATE(),?)",
                        [$adi,$kod,$aciklama,$ikon,$renk,$sira,$durum,$baseUrl,$docsUrl,$user['kullanici_id'],$user['kullanici_id'],$durum]);
                    echo json_encode(['success'=>true,'message'=>'Eklendi.']);
                }
                break;
            case 'delete_entegrasyon':
                $id = intval($_POST['id']??0);
                $db->execute("UPDATE Entegrasyon SET Durum=0,GuncellemeTarihi=GETDATE() WHERE Entegrasyon_Id=?",[$id]);
                echo json_encode(['success'=>true,'message'=>'Silindi.']);
                break;
            case 'save_kanal':
                $id   = intval($_POST['id']??0);
                $eid  = intval($_POST['Kanal_Entegrasyon_Id']??0);
                $adi  = trim($_POST['Kanal_Adi']??'');
                $ortam= trim($_POST['Kanal_Ortam']??'PROD');
                $ep   = trim($_POST['Kanal_Endpoint']??'');
                $ku   = trim($_POST['Kanal_Kullanici']??'') ?: null;
                $ks   = trim($_POST['Kanal_Sifre']??'') ?: null;
                $ka   = trim($_POST['Kanal_ApiKey']??'') ?: null;
                $prm  = trim($_POST['Kanal_Parametreler']??'') ?: null;
                $vars = intval($_POST['Kanal_Varsayilan']??0);
                $kd   = intval($_POST['Kanal_Durum']??1);
                if (!$adi||!$eid) throw new Exception('Ad zorunludur.');
                if ($id>0) {
                    $db->execute("UPDATE EntegrasyonKanallari SET Kanal_Adi=?,Kanal_Ortam=?,Kanal_Endpoint=?,Kanal_Kullanici=?,Kanal_Sifre=?,Kanal_ApiKey=?,Kanal_Parametreler=?,Kanal_Varsayilan=?,Kanal_Durum=?,GuncelleyenKullanici=?,GuncellemeTarihi=GETDATE() WHERE Kanal_Id=?",
                        [$adi,$ortam,$ep,$ku,$ks,$ka,$prm,$vars,$kd,$user['kullanici_id'],$id]);
                } else {
                    $db->execute("INSERT INTO EntegrasyonKanallari(Kanal_Entegrasyon_Id,Kanal_Adi,Kanal_Ortam,Kanal_Endpoint,Kanal_Kullanici,Kanal_Sifre,Kanal_ApiKey,Kanal_Parametreler,Kanal_Varsayilan,Kanal_Durum,OlusturanKullanici,OlusturmaTarihi,GuncelleyenKullanici,GuncellemeTarihi,Durum) VALUES(?,?,?,?,?,?,?,?,?,?,?,GETDATE(),?,GETDATE(),1)",
                        [$eid,$adi,$ortam,$ep,$ku,$ks,$ka,$prm,$vars,$kd,$user['kullanici_id'],$user['kullanici_id']]);
                }
                echo json_encode(['success'=>true,'message'=>'Kanal kaydedildi.']);
                break;
            case 'delete_kanal':
                $id = intval($_POST['id']??0);
                $db->execute("UPDATE EntegrasyonKanallari SET Durum=0,GuncellemeTarihi=GETDATE() WHERE Kanal_Id=?",[$id]);
                echo json_encode(['success'=>true,'message'=>'Kanal silindi.']);
                break;
            case 'test_baglanti':
                $eid = intval($_POST['entegrasyon_id']??0);
                $ent = $db->fetchOne("SELECT Entegrasyon_Kod,Entegrasyon_Adi FROM Entegrasyon WHERE Entegrasyon_Id=?",[$eid]);
                if (!$ent) throw new Exception('Entegrasyon bulunamadı.');
                require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
                $helper = new EntegrasyonHelper($db);
                $sonuc  = $helper->baglantiTest($ent['Entegrasyon_Kod']);
                $sonuc['entegrasyon'] = $ent['Entegrasyon_Adi'];
                echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
                break;
            default:
                echo json_encode(['success'=>false,'message'=>'Geçersiz işlem.']);
        }
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';
$pageTitle = 'Entegrasyon Yönetimi';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<link rel="stylesheet" href="/Admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
<?php include __DIR__.'/../includes/header.php'; ?>
<?php include __DIR__.'/../includes/sidebar.php'; ?>
<main class="app-main">
<div class="app-content-header"><div class="container-fluid"><div class="row">
<div class="col-sm-6"><h3 class="mb-0"><i class="bi bi-plug"></i> <?= htmlspecialchars($pageTitle) ?></h3></div>
<div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li></ol></div>
</div></div></div>
<div class="app-content"><div class="container-fluid">

<!-- Entegrasyonlar Kartı -->
<div class="card card-primary card-outline">
<div class="card-header">
<h3 class="card-title"><i class="bi bi-grid"></i> Entegrasyonlar</h3>
<div class="card-tools">
<button class="btn btn-primary btn-sm" onclick="openEntModal()"><i class="bi bi-plus-circle"></i> Yeni Entegrasyon</button>
</div>
</div>
<div class="card-body">
<table id="entTable" class="table table-bordered table-striped table-hover">
<thead><tr>
<th>#</th><th>İkon</th><th>Kod</th><th>Ad</th><th>Base URL</th><th>Kanal</th><th>Durum</th><th>İşlemler</th>
</tr></thead>
<tbody></tbody>
</table>
</div>
</div>

<!-- Kanallar Kartı -->
<div class="card card-info card-outline" id="kanalKarti" style="display:none">
<div class="card-header">
<h3 class="card-title"><i class="bi bi-diagram-3"></i> Kanallar — <span id="kanalBaslik"></span></h3>
<div class="card-tools">
<button class="btn btn-info btn-sm" onclick="openKanalModal()"><i class="bi bi-plus-circle"></i> Yeni Kanal</button>
</div>
</div>
<div class="card-body">
<table id="kanalTable" class="table table-bordered table-striped">
<thead><tr>
<th>#</th><th>Ad</th><th>Ortam</th><th>Endpoint</th><th>Varsayılan</th><th>Son Kullanım</th><th>Durum</th><th>İşlemler</th>
</tr></thead>
<tbody></tbody>
</table>
</div>
</div>

</div></div>
</main>
<?php include __DIR__.'/../includes/footer.php'; ?>
</div>

<!-- Entegrasyon Modal -->
<div class="modal fade" id="entModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="entModalTitle">Entegrasyon</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<form id="entForm">
<input type="hidden" id="ent_id" name="id">
<div class="modal-body">
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Ad <span class="text-danger">*</span></label><input type="text" class="form-control" name="Entegrasyon_Adi" id="ent_adi" required></div>
<div class="col-md-6"><label class="form-label">Kod <span class="text-danger">*</span></label><input type="text" class="form-control" name="Entegrasyon_Kod" id="ent_kod" required placeholder="ORNEK_KOD"></div>
<div class="col-md-12"><label class="form-label">Açıklama</label><input type="text" class="form-control" name="Entegrasyon_Aciklama" id="ent_aciklama"></div>
<div class="col-md-6"><label class="form-label">Base URL <small class="text-muted">(API ana adresi)</small></label><input type="url" class="form-control" name="Entegrasyon_BaseUrl" id="ent_baseurl" placeholder="https://api.example.com"></div>
<div class="col-md-6"><label class="form-label">Dokümantasyon URL</label><input type="url" class="form-control" name="Entegrasyon_DocsUrl" id="ent_docsurl" placeholder="https://developers.example.com"></div>
<div class="col-md-4"><label class="form-label">İkon (Bootstrap Icons)</label><input type="text" class="form-control" name="Entegrasyon_Ikon" id="ent_ikon" placeholder="bi-plug"></div>
<div class="col-md-4"><label class="form-label">Renk</label><input type="color" class="form-control form-control-color" name="Entegrasyon_Renk" id="ent_renk" value="#1a6eb5"></div>
<div class="col-md-2"><label class="form-label">Sıra</label><input type="number" class="form-control" name="Entegrasyon_Sira" id="ent_sira" value="0"></div>
<div class="col-md-2"><label class="form-label">Durum</label><select class="form-select" name="Entegrasyon_Durum" id="ent_durum"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button></div>
</form>
</div></div>
</div>

<!-- Kanal Modal -->
<div class="modal fade" id="kanalModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="kanalModalTitle">Kanal</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<form id="kanalForm">
<input type="hidden" id="kanal_id" name="id">
<input type="hidden" id="kanal_ent_id" name="Kanal_Entegrasyon_Id">
<div class="modal-body">
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Ad <span class="text-danger">*</span></label><input type="text" class="form-control" name="Kanal_Adi" id="kanal_adi" required></div>
<div class="col-md-3"><label class="form-label">Ortam</label><select class="form-select" name="Kanal_Ortam" id="kanal_ortam"><option value="PROD">PROD</option><option value="TEST">TEST</option></select></div>
<div class="col-md-3"><label class="form-label">Varsayılan</label><select class="form-select" name="Kanal_Varsayilan" id="kanal_varsayilan"><option value="1">Evet</option><option value="0">Hayır</option></select></div>
<div class="col-md-12"><label class="form-label">Endpoint (URL)</label><input type="text" class="form-control" name="Kanal_Endpoint" id="kanal_endpoint" placeholder="https://"></div>
<div class="col-md-6"><label class="form-label">Kullanıcı</label><input type="text" class="form-control" name="Kanal_Kullanici" id="kanal_kullanici"></div>
<div class="col-md-6"><label class="form-label">Şifre</label><input type="password" class="form-control" name="Kanal_Sifre" id="kanal_sifre"></div>
<div class="col-md-12"><label class="form-label">API Key</label><input type="text" class="form-control" name="Kanal_ApiKey" id="kanal_apikey"></div>
<div class="col-md-12"><label class="form-label">Parametreler (JSON)</label><textarea class="form-control" name="Kanal_Parametreler" id="kanal_params" rows="3" placeholder='{"format":"XML"}'></textarea></div>
<div class="col-md-4"><label class="form-label">Durum</label><select class="form-select" name="Kanal_Durum" id="kanal_durum"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-info"><i class="bi bi-save"></i> Kaydet</button></div>
</form>
</div></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script>
let entTable, kanalTable;
let seciliEntId = null;
const entModalBs  = new bootstrap.Modal(document.getElementById('entModal'));
const kanalModalBs = new bootstrap.Modal(document.getElementById('kanalModal'));

$(function() {
    entTable = $('#entTable').DataTable({
        ajax: { url:'', type:'POST', data:()=>({action:'list_entegrasyon'}), dataSrc: r=>r.success?r.data:[] },
        language: { url:'//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        columns: [
            {data:'Entegrasyon_Id'},
            {data:'Entegrasyon_Ikon', render: d=> d?`<i class="${d}" style="font-size:1.4rem"></i>`:'—'},
            {data:'Entegrasyon_Kod', render: d=>`<code>${d}</code>`},
            {data:'Entegrasyon_Adi'},
            {data:'Entegrasyon_BaseUrl', defaultContent:'—', render: d=> d?`<a href="${d}" target="_blank" class="text-truncate d-inline-block" style="max-width:180px" title="${d}">${d}</a>`:'—'},
            {data:'KanalSayisi', className:'text-center', render: d=>`<span class="badge bg-info">${d}</span>`},
            {data:'Entegrasyon_Durum', render: d=>d==1?'<span class="badge bg-success">Aktif</span>':'<span class="badge bg-danger">Pasif</span>'},
            {data:null, orderable:false, render:(d,t,r)=>`
                <button class="btn btn-sm btn-outline-secondary" onclick="kanallarGoster(${r.Entegrasyon_Id},'${r.Entegrasyon_Adi}')" title="Kanallar"><i class="bi bi-diagram-3"></i></button>
                <button class="btn btn-sm btn-outline-success" onclick="testBaglanti(${r.Entegrasyon_Id},this)" title="Bağlantı Testi"><i class="bi bi-broadcast-pin"></i></button>
                ${r.Entegrasyon_DocsUrl?`<a href="${r.Entegrasyon_DocsUrl}" target="_blank" class="btn btn-sm btn-outline-info" title="Dokümantasyon"><i class="bi bi-book"></i></a>`:''}
                <button class="btn btn-sm btn-warning" onclick="editEnt(${r.Entegrasyon_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-danger" onclick="deleteEnt(${r.Entegrasyon_Id})" title="Sil"><i class="bi bi-trash"></i></button>`}
        ]
    });

    kanalTable = $('#kanalTable').DataTable({
        searching:false, paging:false, info:false,
        language: { url:'//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        columns: [
            {data:'Kanal_Id'},
            {data:'Kanal_Adi'},
            {data:'Kanal_Ortam', render:d=>d=='PROD'?'<span class="badge bg-primary">PROD</span>':'<span class="badge bg-warning text-dark">TEST</span>'},
            {data:'Kanal_Endpoint', render:d=>d?`<small class="text-truncate d-inline-block" style="max-width:200px" title="${d}">${d}</small>`:'—'},
            {data:'Kanal_Varsayilan', render:d=>d?'<i class="bi bi-check-circle text-success"></i>':'—'},
            {data:'Kanal_Son_Kullanim', defaultContent:'—'},
            {data:'Kanal_Durum', render:d=>d==1?'<span class="badge bg-success">Aktif</span>':'<span class="badge bg-danger">Pasif</span>'},
            {data:null, orderable:false, render:(d,t,r)=>`
                <button class="btn btn-sm btn-warning" onclick="editKanal(${r.Kanal_Id})"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-danger" onclick="deleteKanal(${r.Kanal_Id})"><i class="bi bi-trash"></i></button>`}
        ]
    });

    $('#entForm').on('submit', function(e){
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('action','save_entegrasyon');
        $.ajax({url:'',method:'POST',data:fd,processData:false,contentType:false,dataType:'json',
            success:r=>{ if(r.success){showToast(r.message,'success');entModalBs.hide();entTable.ajax.reload();}else showToast(r.message,'error'); }
        });
    });

    $('#kanalForm').on('submit', function(e){
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('action','save_kanal');
        $.ajax({url:'',method:'POST',data:fd,processData:false,contentType:false,dataType:'json',
            success:r=>{ if(r.success){showToast(r.message,'success');kanalModalBs.hide();loadKanallar(seciliEntId);}else showToast(r.message,'error'); }
        });
    });
});

function openEntModal(){ $('#entForm')[0].reset();$('#ent_id').val('');$('#entModalTitle').text('Yeni Entegrasyon');entModalBs.show(); }
function editEnt(id){
    $.post('',{action:'list_entegrasyon'},r=>{
        const row = r.data.find(x=>x.Entegrasyon_Id==id);
        if(!row)return;
        $('#ent_id').val(row.Entegrasyon_Id);
        $('#ent_adi').val(row.Entegrasyon_Adi);
        $('#ent_kod').val(row.Entegrasyon_Kod);
        $('#ent_aciklama').val(row.Entegrasyon_Aciklama||'');
        $('#ent_baseurl').val(row.Entegrasyon_BaseUrl||'');
        $('#ent_docsurl').val(row.Entegrasyon_DocsUrl||'');
        $('#ent_ikon').val(row.Entegrasyon_Ikon||'');
        $('#ent_renk').val(row.Entegrasyon_Renk||'#000000');
        $('#ent_sira').val(row.Entegrasyon_Sira||0);
        $('#ent_durum').val(row.Entegrasyon_Durum);
        $('#entModalTitle').text('Entegrasyon Düzenle');
        entModalBs.show();
    },'json');
}
function deleteEnt(id){
    confirmAction('Bu entegrasyonu silmek istiyor musunuz?','',()=>{
        $.post('',{action:'delete_entegrasyon',id:id},r=>{
            if(r.success){showToast(r.message,'success');entTable.ajax.reload();}
            else showError('Hata',r.message);
        },'json');
    });
}

function testBaglanti(eid, btn){
    const $b = $(btn), eski = $b.html();
    $b.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post('',{action:'test_baglanti',entegrasyon_id:eid}, r=>{
        $b.prop('disabled',false).html(eski);
        const detay = r.data ? String(typeof r.data==='object'?JSON.stringify(r.data,null,2):r.data) : '';
        Swal.fire({
            icon: r.success ? 'success' : 'error',
            title: (r.entegrasyon||'Entegrasyon') + ' — ' + (r.success?'Bağlantı Başarılı':'Bağlantı Hatası'),
            html: '<div class="text-start"><p class="mb-2">'+$('<div>').text(r.message||'').html()+'</p>'
                + (detay ? '<pre class="bg-body-tertiary p-2 rounded small text-start" style="max-height:240px;overflow:auto">'+$('<div>').text(detay).html()+'</pre>' : '')
                + '</div>',
            width: 600
        });
        entTable.ajax.reload(null,false);
    },'json').fail(()=>{
        $b.prop('disabled',false).html(eski);
        showError('Hata','Test isteği başarısız oldu.');
    });
}

function kanallarGoster(eid, adi){
    seciliEntId = eid;
    $('#kanalBaslik').text(adi);
    $('#kanal_ent_id').val(eid);
    $('#kanalKarti').show();
    loadKanallar(eid);
    $('html,body').animate({scrollTop:$('#kanalKarti').offset().top-80},400);
}
function loadKanallar(eid){
    $.post('',{action:'list_kanal',entegrasyon_id:eid},r=>{
        kanalTable.clear();
        if(r.success) kanalTable.rows.add(r.data);
        kanalTable.draw();
    },'json');
}
function openKanalModal(){
    $('#kanalForm')[0].reset();
    $('#kanal_id').val('');
    $('#kanal_ent_id').val(seciliEntId);
    $('#kanalModalTitle').text('Yeni Kanal');
    kanalModalBs.show();
}
function editKanal(id){
    $.post('',{action:'list_kanal',entegrasyon_id:seciliEntId},r=>{
        const row = r.data.find(x=>x.Kanal_Id==id);
        if(!row)return;
        $('#kanal_id').val(row.Kanal_Id);
        $('#kanal_ent_id').val(row.Kanal_Entegrasyon_Id);
        $('#kanal_adi').val(row.Kanal_Adi);
        $('#kanal_ortam').val(row.Kanal_Ortam);
        $('#kanal_endpoint').val(row.Kanal_Endpoint||'');
        $('#kanal_kullanici').val(row.Kanal_Kullanici||'');
        $('#kanal_sifre').val('');
        $('#kanal_apikey').val(row.Kanal_ApiKey||'');
        $('#kanal_params').val(row.Kanal_Parametreler||'');
        $('#kanal_varsayilan').val(row.Kanal_Varsayilan);
        $('#kanal_durum').val(row.Kanal_Durum);
        $('#kanalModalTitle').text('Kanal Düzenle');
        kanalModalBs.show();
    },'json');
}
function deleteKanal(id){
    confirmAction('Bu kanalı silmek istiyor musunuz?','',()=>{
        $.post('',{action:'delete_kanal',id:id},r=>{
            if(r.success){showToast(r.message,'success');loadKanallar(seciliEntId);}
            else showError('Hata',r.message);
        },'json');
    });
}
</script>
</body>
</html>
