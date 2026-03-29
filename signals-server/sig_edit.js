    function DeleteSignal(id) {
       document.location="script?delete=" + id; 
    }
    function EditAmount(id, def) {
       document.location="script?sig_id=" + id + "&amount=" + prompt("Enter amount value signal", def);
    }    
    function EditQty(id, def) {
      document.location="script?edit_qty=" + id + "&qty=" + prompt("Enter quantity for signal", def);
   }        
    function EditTP(id, def) {
       document.location="script?edit_tp=" + id + "&price=" + prompt("Enter take profit price for signal", def);
    }
    function EditSL(id, def) {
       document.location="script?edit_sl=" + id + "&price=" + prompt("Enter stop loss price for signal", def);
    }    
    function EditLP(id, def) {
       document.location="script?edit_lp=" + id + "&price=" + prompt("Enter limit price for signal", def);
    }        
    
    function EditComment(id, def) {
      document.location="script?edit_comment=" + id + "&text=" + prompt("Enter comment for signal", def);
    }

    function ToggleTP(id) {
       document.location="script?toggle_tp=" + id;
    }
    function ToggleSL(id) {
       document.location="script?toggle_sl=" + id;
    }
    function ToggleSE(id) {
      document.location="script?toggle_se=" + id;
   }
    function ToggleLP(id) {
       document.location="script?toggle_lp=" + id;
    }    
    function GoHome() {       
      document.location='script';
    }